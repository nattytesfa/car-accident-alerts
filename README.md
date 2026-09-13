# Accident Alerts

Real-time accident alert notification system for hospitals. An Arduino sends GPS accident data over serial to a Python bridge, which pushes alerts to **Telegram** and stores them on a live **web dashboard** with Google Maps integration.

## How It Works

```
Arduino (GPS + sensors)
    |  Serial / USB
    v
telegram_bridge.py        (Python)
    |          \
    v           v
Telegram     endpoint.php  (PHP)
              |            |
              v            v
          MySQL DB --> dashboard.php  (auto-refreshes every 10s)
```

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

Edit the top of `telegram_bridge.py`:

```python
SERIAL_PORT = "/dev/ttyACM0"          # see below to find your port
BOT_TOKEN   = "1234567890:AAHf..."    # token from BotFather
SERVER_URL  = "http://localhost/accident-alerts/endpoint.php"
```

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

The script starts listening on the serial port. When the Arduino sends an alert block, it will:
- Forward it as a Telegram message to your chat
- Save it to the database so it appears on the web dashboard

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

## Project Structure

```
accident-alerts/
├── setup.sql              # Database schema
├── db.php                 # Database connection
├── login.php              # User login page
├── register.php           # Account registration
├── logout.php             # Session logout
├── auth_check.php         # Authentication middleware
├── dashboard.php          # Live command center dashboard
├── endpoint.php           # HTTP API for receiving alerts
├── styles.css             # Professional UI stylesheet
├── telegram_bridge.py     # Arduino → Telegram + dashboard bridge
└── README.md
```

## Security Notes

- Passwords are hashed with `password_hash()` (bcrypt) — never stored in plain text
- SQL queries use prepared statements to prevent injection
- All HTML output is escaped with `htmlspecialchars()` to prevent XSS
- Never commit `BOT_TOKEN` or MySQL passwords to a public repository
