# Accident Alerts

Real-time accident alert notification system for hospitals. An Arduino sends GPS accident data over serial to a Python bridge, which pushes alerts to **Telegram** and stores them on a live **web dashboard** with Google Maps integration.

## How It Works

```
Arduino (GPS + sensors)
    |  Serial / USB  (alerts ↑, hospital list ↓)
    v
telegram_bridge.py        (Python)
    |          \            |
    v           v           v
Telegram  endpoint.php  register_hospital.php
            |            |
            v            v
        MySQL DB --> dashboard.php  (auto-refreshes every 10s)
```

Hospitals register through the **Telegram bot**, are saved to MySQL, get
**admin approval** on the dashboard, and only then does the bridge push them to
the Arduino over serial — so it always computes the nearest hospital from
approved live data.

## Features

- Live command center dashboard with key statistics
- One-click Google Maps links for every accident location
- Instant Telegram notifications with structured alert messages
- Secure login system with password hashing
- Self-service account registration
- Auto-refreshing dashboard (every 10 seconds)
- Professional, responsive UI

## Prerequisites

| Component | Version | Purpose |
|-----------|---------|---------|
| PHP | 7.4+ | Web dashboard and API endpoint |
| MySQL / MariaDB | 5.7+ | Database |
| XAMPP / WAMP / LAMP | — | Apache + MySQL stack |
| Python | 3.7+ | Serial-to-Telegram bridge |
| Arduino board | — | Reads sensors / GPS, sends alert over serial |

### Python dependencies

```
pip install pyserial requests
```

## Quick Start

### 1. Clone the repository

```bash
git clone https://github.com/your-username/accident-alerts.git
cd accident-alerts
```

### 2. Set up the database

Make sure MySQL is running, then import the schema:

```bash
mysql -u root < setup.sql
```

> If you have a MySQL password, use: `mysql -u root -p < setup.sql`

### 3. Configure the database connection

Edit `db.php` if your MySQL credentials differ:

```php
$host = "localhost";
$user = "root";
$pass = "";          // your MySQL password
$dbname = "accident_alerts";
```

### 4. Start the web dashboard

Start your XAMPP/LAMP stack (Apache + MySQL) and open:

```
http://localhost/accident-alerts/login.php
```

Register an account and sign in.

### 5. Create a Telegram bot

1. Open Telegram and message **[@BotFather](https://t.me/BotFather)**
2. Send `/newbot`, then choose a name (e.g. `Accident Alert Bot`)
3. Choose a username (must end in `bot`, e.g. `youraccidentalert_bot`)
4. BotFather replies with a **token** — copy it (looks like `1234567890:AAHf...`)

### 6. Find your Telegram chat ID

1. Open Telegram and **send any message** to your new bot
2. Open this URL in your browser (replace `<BOT_TOKEN>` with the token from step 5):
   ```
   https://api.telegram.org/bot<BOT_TOKEN>/getUpdates
   ```
3. Look for `"chat":{"id": XXXXXXXXX}` — that number is your **chat ID**

### 7. Configure the Python bridge

Edit the top of `telegram_bridge.py` for the serial port:

```python
SERIAL_PORT = "/dev/ttyACM0"          # see below to find your port
SERVER_BASE = "http://localhost/accident-alerts"
```

Set the bot token via an **environment variable** (it is never stored in the file):

```bash
export ACCIDENT_ALERTS_BOT_TOKEN="1234567890:AAHf..."
```

To make it persistent, add that line to `~/.bashrc` (Linux/macOS) or set it as a
user environment variable (Windows).

#### Finding your serial port

| OS | Command to find port |
|----|----------------------|
| Linux | `ls /dev/ttyACM*` or `ls /dev/ttyUSB*` |
| macOS | `ls /dev/tty.usbmodem*` or `ls /dev/tty.usbserial*` |
| Windows | Device Manager → Ports (COM & LPT) → look for Arduino |

### 8. Run the bridge

```bash
python3 telegram_bridge.py
```

At startup it pushes the full hospital list (from MySQL) to the Arduino over serial.
It then listens for Arduino alerts, forwarding each one to Telegram and saving it
to the dashboard. It also polls the bot for commands (next step).

### 9. Register hospitals via Telegram

Message your bot and send:

```
/registerhospital Central General Hospital
```

The bot will ask for **latitude**, then **longitude**, then the **chat ID**
that should receive that hospital's alerts (send `default` to use the current chat):

```
📍 Hospital <Central General Hospital>
Send the latitude (e.g. 8.554962):
```

Once saved, the hospital is stored in MySQL as **pending** and the bot replies:

> ✅ Hospital <Central General Hospital> registered ... ⏳ Waiting for **admin approval**. It will be sent to the Arduino automatically once approved.

### 10. Admin approval (required before sync)

A hospital is **never** pushed to the Arduino while pending. An admin must
approve it on the dashboard first:

1. Sign in with the admin account (`admin`)
2. The **Pending Hospital Approvals** panel lists every unapproved request
3. Click **✓ Approve** (mark approved) or **✕ Reject** (mark rejected)
4. The bridge detects the change (checks every ~30s) and pushes the updated
   list to the Arduino — no restart needed

Rejected hospitals are kept with a `rejected` status (they never reach the
Arduino). The dashboard shows live counts of **Approved / Pending / Rejected**
hospitals and the "Approved Hospitals" stat reflects the registered list,
not just hospitals that have sent alerts.

The hospital's registrant is **notified on Telegram** within ~10s of the decision:

> 🏥 Hospital <Muse 2 Hospital> was **approved** ✅ — It is now active for accident alert routing.

Only approved hospitals appear in `hospitals_sync.php`, so pending data can
never reach the Arduino.

Other commands:

| Command | Action |
|---------|--------|
| `/registerhospital <name>` | Start hospital registration |
| `/hospitals` | List all **approved** hospitals |
| `/start` | Show available commands |

## Arduino Serial Format

The bridge expects alerts in this format over serial:

```
===ALERT_START===
HOSPITAL:Central General Hospital
CHATID:123456789
LAT:38.761700
LNG:-9.139400
DISTANCE:3.2
MAPLINK:https://maps.google.com/?q=38.7617,-9.1394
===ALERT_END===
```

Fields:

| Field | Description |
|-------|-------------|
| `HOSPITAL` | Name of the nearest hospital |
| `CHATID` | Telegram chat ID to send the notification to |
| `LAT` | GPS latitude |
| `LNG` | GPS longitude |
| `DISTANCE` | Distance to hospital in km |
| `MAPLINK` | Pre-built Google Maps link |

## HTTP Endpoint API

`endpoint.php` can also be called directly via HTTP POST (without the Python bridge):

```bash
curl -X POST http://localhost/accident-alerts/endpoint.php \
  -d "lat=38.7617&lng=-9.1394&hospital=Central+General+Hospital"
```

Success response:
```json
{"status":"ok","message":"Alert stored"}
```

`register_hospital.php` can also be called directly over HTTP:

```bash
curl -X POST http://localhost/accident-alerts/register_hospital.php \
  -d "name=Central+General+Hospital&lat=8.554962&lng=39.277962&chat_id=123456789"
```

## Arduino

The full sketch is in `arduino/accident_alerts.ino`. It computes the nearest
hospital at alert time using the **live** list it receives from the bridge.

Live list sync format sent by the bridge over serial:

```
===HOSPITALS_START===
H:Central General Hospital|8.554962|39.277962|123456789
H:Adama General Hospital|8.561010|39.291380|379998469
===HOSPITALS_END===
```

Fields per `H:` line, separated by `|`:

| Field | Description |
|-------|-------------|
| name | Hospital name |
| lat | Hospital latitude |
| lng | Hospital longitude |
| chatID | Telegram chat ID to alert for that hospital |

> A small default list is compiled into the sketch so it still works with no
> PC connected; the first bridge sync replaces it.

## Project Structure

```
accident-alerts/
├── setup.sql              # Database schema (alerts, users, hospitals)
├── db.php                 # Database connection
├── login.php              # User login page
├── register.php           # Account registration
├── logout.php             # Session logout
├── auth_check.php         # Authentication middleware
├── dashboard.php          # Live command center dashboard
├── endpoint.php           # HTTP API for receiving alerts
├── register_hospital.php  # HTTP API for hospital registration (bot) — saves as pending
├── approve_hospital.php   # Admin-only approve/reject + queues notification
├── hospitals_sync.php     # HTTP API returning APPROVED hospitals (for Arduino sync)
├── pending_notifications.php  # Unsent approve/reject notices (consumed by bridge)
├── mark_notifications.php     # Marks notices delivered after Telegram send
├── styles.css             # Professional UI stylesheet
├── telegram_bridge.py     # Arduino ↔ Telegram + dashboard bridge
├── arduino/
│   └── accident_alerts.ino  # Arduino firmware (live hospital list support)
└── README.md
```

## Security Notes

- Passwords are hashed with `password_hash()` (bcrypt) — never stored in plain text
- SQL queries use prepared statements to prevent injection
- All HTML output is escaped with `htmlspecialchars()` to prevent XSS
- The Telegram bot token is read from the `ACCIDENT_ALERTS_BOT_TOKEN` environment variable — never commit it to the repository
