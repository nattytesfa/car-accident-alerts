import serial
import requests
import time
from datetime import datetime

# --- CONFIGURATION ---
SERIAL_PORT = "/dev/ttyACM0"
BAUD_RATE = 9600
BOT_TOKEN = ""

# PHP web app endpoint (endpoint.php validates and stores alerts)
SERVER_URL = "http://localhost/accident-alerts/endpoint.php"

def send_telegram_message(chat_id, message):
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
    payload = {
        "chat_id": chat_id,
        "text": message
    }
    try:
        response = requests.post(url, json=payload)
        response.raise_for_status()
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Sent to chat {chat_id}")
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error sending Telegram: {e}")

def save_alert_to_dashboard(hospital, lat, lng):
    data = {
        "lat": lat,
        "lng": lng,
        "hospital": hospital,
    }
    try:
        response = requests.post(SERVER_URL, data=data, timeout=10)
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Dashboard save: "
              f"HTTP {response.status_code} {response.text}")
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error saving to dashboard: {e}")

def format_alert(hospital, lat, lng, distance, maplink):
    now = datetime.now().strftime("%H:%M:%S")
    return (
        f"🚨 ACCIDENT ALERT 🚨\n\n"
        f"An accident has been detected!\n\n"
        f"📍 Location:\n{maplink}\n\n"
        f"🏥 Nearest Hospital:\n{hospital}\n\n"
        f"📏 Distance:\n{distance} km\n\n"
        f"⏰ Time: {now}\n\n"
        f"Please respond immediately!"
    )

def main():
    print(f"Opening serial port {SERIAL_PORT}...")
    try:
        ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
        time.sleep(2)
        print("Listening for Arduino alerts...")
    except Exception as e:
        print(f"Error opening serial port: {e}")
        return

    in_alert = False
    hospital = ""
    chat_id = ""
    lat = ""
    lng = ""
    distance = ""
    maplink = ""

    while True:
        try:
            if ser.in_waiting > 0:
                line = ser.readline().decode("utf-8", errors="ignore").strip()

                if line == "===ALERT_START===":
                    in_alert = True
                    hospital = chat_id = lat = lng = distance = maplink = ""
                    continue

                if line == "===ALERT_END===" and in_alert:
                    in_alert = False
                    msg = format_alert(hospital, lat, lng, distance, maplink)
                    send_telegram_message(chat_id, msg)
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

        except Exception as e:
            print(f"Error: {e}")
            break

if __name__ == "__main__":
    main()