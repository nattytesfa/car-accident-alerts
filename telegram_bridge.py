import hashlib
import json
import os
import re
import serial
import requests
import time
from datetime import datetime

# --- CONFIGURATION ----------------------------------------------------
SERIAL_PORT = "/dev/ttyACM0"
BAUD_RATE = 9600

# Read the bot token from the environment (never hardcode it in the repo).
BOT_TOKEN = os.environ.get("ACCIDENT_ALERTS_BOT_TOKEN")
if not BOT_TOKEN:
    print("ERROR: set ACCIDENT_ALERTS_BOT_TOKEN (your Telegram bot token), e.g.")
    print('  export ACCIDENT_ALERTS_BOT_TOKEN="1234567890:AAHf..."')
    raise SystemExit(1)

SERVER_BASE = "http://localhost/accident-alerts"
ALERT_ENDPOINT = f"{SERVER_BASE}/endpoint.php"
REGISTER_HOSPITAL_ENDPOINT = f"{SERVER_BASE}/register_hospital.php"
HOSPITALS_SYNC_ENDPOINT = f"{SERVER_BASE}/hospitals_sync.php"
NOTIFICATIONS_PENDING_ENDPOINT = f"{SERVER_BASE}/pending_notifications.php"
NOTIFICATIONS_MARK_ENDPOINT = f"{SERVER_BASE}/mark_notifications.php"

# How often (seconds) to check for newly approved/updated hospitals and
# push the list to the Arduino.
PUSH_CHECK_INTERVAL = 30

# How often (seconds) to check for approval/rejection notifications to forward.
NOTIFICATION_CHECK_INTERVAL = 10

_last_pushed_hash = None

def list_hash(hospitals):
    return hashlib.sha256(json.dumps(hospitals, sort_keys=True).encode("utf-8")).hexdigest()

# --- Telegram helpers -------------------------------------------------
_last_update_id = 0

def log(msg):
    """Append a line to bridge.log for debugging."""
    try:
        with open("bridge.log", "a") as f:
            f.write(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}\n")
    except Exception:
        pass
    print(f"[{now()}] {msg}")

def tg_send(chat_id, message):
    """Plain-text send (no parse_mode — avoids 400s from literal < > in bots)."""
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
    try:
        r = requests.post(url, json={"chat_id": chat_id, "text": message}, timeout=10)
        r.raise_for_status()
        log(f"SENT -> chat {chat_id}: {message[:60].replace(chr(10), ' ')}")
    except Exception as e:
        log(f"SEND FAIL -> chat {chat_id}: {e}")

def tg_get_updates():
    """Non-blocking long poll; returns list of updates."""
    global _last_update_id
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/getUpdates"
    try:
        r = requests.get(url, params={"offset": _last_update_id + 1, "timeout": 0}, timeout=10)
        data = r.json()
    except Exception as e:
        log(f"getUpdates error: {e}")
        return []

    if not data.get("ok"):
        log(f"getUpdates API: {data}")
        return []

    updates = data.get("result", [])
    for u in updates:
        _last_update_id = max(_last_update_id, u["update_id"])
        m = u.get("message", {})
        log(f"RECV update {u['update_id']} from chat {m.get('chat', {}).get('id', '?')}: "
            f"{(m.get('text') or 'NO-TEXT')[:60].replace(chr(10), ' ')}")
    return updates

def now():
    return datetime.now().strftime("%H:%M:%S")

# --- Alert flow -------------------------------------------------------
def format_alert(hospital, lat, lng, distance, maplink):
    return (
        f"🚨 ACCIDENT ALERT 🚨\n\n"
        f"An accident has been detected!\n\n"
        f"📍 Location:\n{maplink}\n\n"
        f"🏥 Nearest Hospital:\n{hospital}\n\n"
        f"📏 Distance:\n{distance} km\n\n"
        f"⏰ Time: {now()}\n\n"
        f"Please respond immediately!"
    )

def save_alert_to_dashboard(hospital, lat, lng):
    try:
        r = requests.post(ALERT_ENDPOINT, data={"lat": lat, "lng": lng, "hospital": hospital}, timeout=10)
        print(f"[{now()}] Dashboard save: HTTP {r.status_code} {r.text}")
    except Exception as e:
        print(f"[{now()}] Error saving to dashboard: {e}")

# --- Hospital sync (DB -> serial -> Arduino) --------------------------
def fetch_hospitals():
    try:
        r = requests.get(HOSPITALS_SYNC_ENDPOINT, timeout=10)
        return r.json()
    except Exception as e:
        print(f"[{now()}] Error fetching hospitals: {e}")
        return []

def push_hospitals_to_arduino(ser):
    """Write the current (approved) hospital list to the Arduino over serial."""
    global _last_pushed_hash
    hospitals = fetch_hospitals()
    current_hash = list_hash(hospitals)

    if current_hash == _last_pushed_hash:
        return

    if not hospitals:
        print(f"[{now()}] No approved hospitals to sync yet.")
        _last_pushed_hash = current_hash
        return

    lines = ["===HOSPITALS_START==="]
    for h in hospitals:
        name = re.sub(r"[|\n\r]", " ", h["name"])[:40]
        line = f"H:{name}|{float(h['lat']):.6f}|{float(h['lng']):.6f}|{h.get('chat_id','')}".rstrip("\n")
        lines.append(line)
    lines.append("===HOSPITALS_END===")

    try:
        ser.reset_input_buffer()
        ser.write(("\n".join(lines) + "\n").encode("utf-8"))
        ser.flush()
        _last_pushed_hash = current_hash
        print(f"[{now()}] Pushed {len(hospitals)} approved hospitals to Arduino.")
    except Exception as e:
        print(f"[{now()}] Error writing hospitals to Arduino: {e}")

# --- Approval/rejection notifications (dashboard -> bridge -> Telegram) -
def process_notifications():
    """Forward pending approve/reject notifications to the registrant's chat."""
    try:
        r = requests.get(NOTIFICATIONS_PENDING_ENDPOINT, timeout=10)
        notifications = r.json()
    except Exception as e:
        print(f"[{now()}] Error fetching notifications: {e}")
        return

    if not notifications:
        return

    sent_ids = []
    for n in notifications:
        if n["type"] == "approved":
            text = (f"🏥 Hospital *{n['hospital']}* was APPROVED ✅\n\n"
                    f"It is now active for accident alert routing.")
        else:
            text = (f"🏥 Hospital *{n['hospital']}* was REJECTED ❌\n\n"
                    f"It will not be used by the system. Contact the admin if you "
                    f"think this was a mistake.")
        tg_send(n["chat_id"], text)
        sent_ids.append(str(n["id"]))
        print(f"[{now()}] Notified chat {n['chat_id']}: {n['hospital']} {n['type']}")

    try:
        requests.post(NOTIFICATIONS_MARK_ENDPOINT, data={"ids": ",".join(sent_ids)}, timeout=10)
    except Exception as e:
        print(f"[{now()}] Error marking notifications delivered: {e}")

# --- Telegram hospital registration conversation ----------------------
# state[chat_id] = {"step": "lat"|"lng"|"chat", "name": "..."}
registration_state = {}

def handle_command(chat_id, text):
    if text == "/start":
        tg_send(chat_id, "🤖 Accident Alerts Bot\n\n"
                          "/registerhospital <name> - register a hospital\n"
                          "/hospitals - list registered hospitals")
        return
    if text == "/hospitals":
        hospitals = fetch_hospitals()
        if not hospitals:
            tg_send(chat_id, "No hospitals registered yet.")
        else:
            msg = "🏥 Registered hospitals:\n\n"
            for h in hospitals:
                msg += f"• {h['name']} ({h['lat']:.6f}, {h['lng']:.6f})\n"
            tg_send(chat_id, msg)
        return

    m = re.match(r"^/registerhospital\s+(.+)$", text)
    if m:
        name = m.group(1).strip()
        if name:
            registration_state[chat_id] = {"step": "lat", "name": name}
            tg_send(chat_id, f"📍 Hospital *{name}*\n\nSend the latitude (e.g. 8.554962):")
        else:
            tg_send(chat_id, "Usage: /registerhospital Hospital Name")
        return

    # Driver for the registration conversation
    if chat_id in registration_state:
        step = registration_state[chat_id]["step"]

        if step == "lat" and is_num(text):
            registration_state[chat_id]["lat"] = text.strip()
            registration_state[chat_id]["step"] = "lng"
            tg_send(chat_id, "📐 Now send the longitude (e.g. 39.277962):")
        elif step == "lng" and is_num(text):
            registration_state[chat_id]["lng"] = text.strip()
            registration_state[chat_id]["step"] = "chat"
            tg_send(chat_id, "💬 Send the Telegram chat ID that should receive this hospital's alerts,\nor send `default` to use this chat.")
        elif step == "chat":
            if text.strip().lower() == "default" or is_num(text):
                chatID = chat_id if text.strip().lower() == "default" else text.strip()
                complete_registration(chat_id, registration_state[chat_id], chatID)
            else:
                tg_send(chat_id, "Send a numeric chat ID, or `default`.")
        else:
            tg_send(chat_id, "Send a number, please.")
    else:
        tg_send(chat_id, "Use /registerhospital <name> to register a hospital.")

def is_num(text):
    try:
        float(text.strip())
        return True
    except (TypeError, ValueError):
        return False

def complete_registration(chat_id, state, chatID):
    name = state["name"]
    lat = state["lat"]
    lng = state["lng"]

    try:
        r = requests.post(REGISTER_HOSPITAL_ENDPOINT,
                          data={"name": name, "lat": lat, "lng": lng, "chat_id": chatID}, timeout=10)
        print(f"[{now()}] register_hospital: HTTP {r.status_code} {r.text}")
        tg_send(chat_id, f"✅ Hospital *{name}* registered:\n📍 {lat}, {lng}\n🔔 alerts → chat {chatID}\n\n"
                          f"⏳ Waiting for *admin approval*. It will be sent to the "
                          f"Arduino automatically once approved.")
    except Exception as e:
        print(f"[{now()}] Error registering hospital: {e}")
        tg_send(chat_id, "❌ Could not save the hospital. Check the server is running.")

    registration_state.pop(chat_id, None)

# --- Serial alert parsing (Arduino -> bridge) -------------------------
def main():
    print(f"Opening serial port {SERIAL_PORT}...")
    try:
        ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
        time.sleep(2)
        print("Serial connected.")
    except Exception as e:
        print(f"Error opening serial port: {e}")
        return

    # Push the current hospital list at startup.
    push_hospitals_to_arduino(ser)

    in_alert = False
    hospital = chat_id = lat = lng = distance = maplink = ""
    last_push_check = 0
    last_notify_check = 0
    last_poll = 0

    print("Listening for Arduino alerts + Telegram commands...")

    while True:
        try:
            # 1) Read Arduino serial
            if ser.in_waiting > 0:
                line = ser.readline().decode("utf-8", errors="ignore").strip()

                if line == "===ALERT_START===":
                    in_alert = True
                    hospital = chat_id = lat = lng = distance = maplink = ""
                    continue

                if line == "===ALERT_END===" and in_alert:
                    in_alert = False
                    send_telegram_message(chat_id, format_alert(hospital, lat, lng, distance, maplink))
                    save_alert_to_dashboard(hospital, lat, lng)
                    continue

                if in_alert:
                    if line.startswith("HOSPITAL:"):
                        hospital = line.replace("HOSPITAL:", "").strip()
                    elif line.startswith("CHATID:"):
                        chat_id = line.replace("CHATID:", "").strip()
                    elif line.startswith("LAT:"):
                        lat = line.replace("LAT:", "").strip()
                    elif line.startswith("LNG:"):
                        lng = line.replace("LNG:", "").strip()
                    elif line.startswith("DISTANCE:"):
                        distance = line.replace("DISTANCE:", "").strip()
                    elif line.startswith("MAPLINK:"):
                        maplink = line.replace("MAPLINK:", "").strip()

            # 2) Poll Telegram roughly every second
            if time.time() - last_poll >= 1:
                last_poll = time.time()
                for update in tg_get_updates():
                    msg = update.get("message", {})
                    txt = msg.get("text", "")
                    if txt:
                        handle_command(msg["chat"]["id"], txt)

            # 3) Push approved hospitals to the Arduino when the list changes
            if time.time() - last_push_check >= PUSH_CHECK_INTERVAL:
                last_push_check = time.time()
                push_hospitals_to_arduino(ser)

            # 4) Forward approval/rejection notifications to hospital chats
            if time.time() - last_notify_check >= NOTIFICATION_CHECK_INTERVAL:
                last_notify_check = time.time()
                process_notifications()

            time.sleep(0.05)

        except (KeyboardInterrupt, SystemExit):
            raise
        except Exception as e:
            print(f"[{now()}] Error: {e}")
            time.sleep(1)

def send_telegram_message(chat_id, message):
    tg_send(chat_id, message)

if __name__ == "__main__":
    main()