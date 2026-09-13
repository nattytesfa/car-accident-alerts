/*
 * accident_alerts.ino
 *
 * Accident alert device:
 *  - MPU6050 shock detection with 10s confirmation window + manual button
 *  - GPS (NEO-6M on SoftwareSerial 2,3) for location
 *  - Picks the *nearest* registered hospital and prints the alert block
 *    to USB serial for telegram_bridge.py to pick up
 *
 * NEW: Hospitals are no longer hardcoded-only. The Python bridge pushes a
 * live list over USB serial as:
 *
 *   ===HOSPITALS_START===
 *   H:<name>|<lat>|<lng>|<chatID>
 *   ...
 *   ===HOSPITALS_END===
 *
 * Until the first sync arrives, a default list is used so everything
 * still works without the PC.
 */

#include <SoftwareSerial.h>
#include <TinyGPS++.h>
#include <Wire.h>
#include <MPU6050.h>
#include <LiquidCrystal_I2C.h>
#include <math.h>

#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif

SoftwareSerial GPSModule(2, 3);          // GPS TX->2, RX->3
TinyGPSPlus gps;
MPU6050 mpu;
LiquidCrystal_I2C lcd(0x27, 16, 2);

const int buttonPin = 10;
int Buzzer = 13;

bool accidentDetected = false;
bool alertSent = false;
bool systemHalted = false;
unsigned long accidentTime = 0;
const unsigned long confirmInterval = 10000;   // 10s window
const unsigned long debounceDelay = 50;
const unsigned long postCancelDelay = 5000;

float currentLatitude = 0.0;
float currentLongitude = 0.0;
bool gpsValid = false;

// ---------------- Hospital storage (RAM, live) -----------------------
struct Hospital {
  char name[40];
  float latitude;
  float longitude;
  char chatID[24];
};

#define MAX_HOSPITALS 15
Hospital hospitals[MAX_HOSPITALS];
int hospitalCount = 0;

bool receivingHospitals = false;
char serialBuffer[96];
int serialIndex = 0;

// ---------------- Helpers --------------------------------------------
void addHospital(const char* name, float lat, float lng, const char* chatID) {
  if (hospitalCount >= MAX_HOSPITALS) return;
  Hospital* h = &hospitals[hospitalCount];
  strncpy(h->name, name, 39);
  h->name[39] = '\0';
  h->latitude = lat;
  h->longitude = lng;
  strncpy(h->chatID, chatID, 23);
  h->chatID[23] = '\0';
  hospitalCount++;
}

void seedDefaultHospitals() {
  addHospital("Muse General Hospital", 8.554962, 39.277962, "379998469");
  addHospital("Adama General Hospital", 8.561010, 39.291380, "379998469");
  addHospital("Haile Mariam Hospital", 8.341224, 39.149702, "379998469");
  Serial.println("[SYNC] Using default hospitals (waiting for live list).");
}

void parseHospitalLine(char* data) {
  if (hospitalCount >= MAX_HOSPITALS) return;

  char* name = strtok(data, "|");
  char* latS = strtok(NULL, "|");
  char* lngS = strtok(NULL, "|");
  char* chatS = strtok(NULL, "|");
  if (!name || !latS || !lngS) return;

  Hospital* h = &hospitals[hospitalCount];
  strncpy(h->name, name, 39);
  h->name[39] = '\0';
  h->latitude = atof(latS);
  h->longitude = atof(lngS);
  strncpy(h->chatID, chatS ? chatS : "", 23);
  h->chatID[23] = '\0';
  hospitalCount++;
}

void handleSerialLine(const char* line) {
  if (receivingHospitals) {
    if (strncmp(line, "===HOSPITALS_END===", 19) == 0) {
      receivingHospitals = false;
      Serial.print("[SYNC] Hospital list updated: ");
      Serial.println(hospitalCount);
      lcd.setCursor(0, 0);
      lcd.print("Hospitals: ");
      lcd.print(hospitalCount);
    } else if (strncmp(line, "H:", 2) == 0) {
      char buffer[96];
      strncpy(buffer, line + 2, sizeof(buffer) - 1);
      buffer[sizeof(buffer) - 1] = '\0';
      parseHospitalLine(buffer);
    }
  } else if (strncmp(line, "===HOSPITALS_START===", 21) == 0) {
    receivingHospitals = true;
    hospitalCount = 0;
  }
}

void readSerialCommands() {
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n') {
      serialBuffer[serialIndex] = '\0';
      handleSerialLine(serialBuffer);
      serialIndex = 0;
    } else if (serialIndex < (int)sizeof(serialBuffer) - 1) {
      serialBuffer[serialIndex++] = c;
    }
  }
}

void blinkAlarm() {
  tone(Buzzer, 1000);
  delay(500);
  noTone(Buzzer);
  delay(500);
}

float degreesToRadians(float degrees) {
  return degrees * (float)M_PI / 180.0;
}

// ---------------- GPS / sensors --------------------------------------
bool getGPSLocation() {
  unsigned long int start = millis();
  while (millis() - start < 2000) {
    while (GPSModule.available()) {
      char c = GPSModule.read();
      if (gps.encode(c)) {
        if (gps.location.isValid()) {
          currentLatitude = gps.location.lat();
          currentLongitude = gps.location.lng();
          gpsValid = true;
          return true;
        }
      }
    }
  }
  return false;
}

#define EARTH_RADIUS_KM 6371.0

float haversine(float lat1, float lon1, float lat2, float lon2) {
  float dLat = degreesToRadians(lat2 - lat1);
  float dLon = degreesToRadians(lon2 - lon1);
  float a = sin(dLat / 2) * sin(dLat / 2) +
            cos(degreesToRadians(lat1)) * cos(degreesToRadians(lat2)) *
            sin(dLon / 2) * sin(dLon / 2);
  float c = 2 * atan2(sqrt(a), sqrt(1 - a));
  return EARTH_RADIUS_KM * c;
}

// ---------------- Alert sending (to bridge via USB) -------------------
void sendAlert() {
  float minDistance = 1e9;
  int nearestIndex = -1;

  for (int i = 0; i < hospitalCount; i++) {
    float d = haversine(currentLatitude, currentLongitude,
                        hospitals[i].latitude, hospitals[i].longitude);
    if (d < minDistance) {
      minDistance = d;
      nearestIndex = i;
    }
  }

  if (nearestIndex == -1) {
    return;
  }

  float dist = minDistance;

  String mapsLink = "https://maps.google.com/?q=";
  mapsLink += String(currentLatitude, 6) + "," + String(currentLongitude, 6);

  Serial.println("===ALERT_START===");
  Serial.println("HOSPITAL:" + String(hospitals[nearestIndex].name));
  Serial.println("CHATID:" + String(hospitals[nearestIndex].chatID));
  Serial.println("LAT:" + String(currentLatitude, 6));
  Serial.println("LNG:" + String(currentLongitude, 6));
  Serial.println("DISTANCE:" + String(dist, 2));
  Serial.println("MAPLINK:" + mapsLink);
  Serial.println("===ALERT_END===");

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Accident!Send");
  lcd.setCursor(0, 1);
  lcd.print(hospitals[nearestIndex].name);
}

// ---------------- Alarm triggering ------------------------------------
void triggerAccident() {
  accidentDetected = true;
  accidentTime = millis();
  alertSent = false;
  Serial.println("[DEBUG] Entering 10s confirmation window.");
}

void sendConfirmation() {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Accident? Send?");
  lcd.setCursor(0, 1);
  lcd.print("Yes: button  10s");
}

// Angle/trigger reading (MPU6050 fall + threshold)
float getTriggerAngle() {
  mpu.update();
  return mpu.angleX;
}

bool manualConfirmPressed() {
  static bool last = false;
  unsigned long lastDebounce = 0;
  bool cur = (digitalRead(buttonPin) == LOW);
  if (cur != last) lastDebounce = millis();
  last = cur;
  return cur && (millis() - lastDebounce > debounceDelay);
}

// Runs the 10s confirmation window; operator must hold the button to confirm.
bool confirmAccident() {
  while ((unsigned long)(millis() - accidentTime) < confirmInterval) {
    if (manualConfirmPressed()) return true;
    delay(50);
  }
  return false;
}

// ---------------- Setup ------------------------------------------------
void setup() {
  pinMode(buttonPin, INPUT_PULLUP);
  pinMode(Buzzer, OUTPUT);

  Serial.begin(9600);
  GPSModule.begin(9600);

  Wire.begin();
  mpu.initialize();
  mpu.calibrateGyro();
  mpu.setThreshold(3);

  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0);
  lcd.print("Accident Alert");
  lcd.setCursor(0, 1);
  lcd.print("ready...");

  for (int i = 0; i < 5; i++) blinkAlarm();

  seedDefaultHospitals();
  Serial.println("[DEBUG] Device ready.");
}

// ---------------- Main loop -------------------------------------------
void loop() {
  // Process hospital sync commands from the bridge at all times.
  readSerialCommands();

  if (!accidentDetected && !systemHalted) {
    if (getTriggerAngle() > 15 || mpu.angleZ > 15) {
      triggerAccident();
      sendConfirmation();
    }
    delay(50);
    return;
  }

  if (accidentDetected && !alertSent) {
    bool confirmed = confirmAccident();

    if (confirmed) {
      Serial.println("[DEBUG] Confirmed. Getting GPS...");
      getGPSLocation();
      sendAlert();
      alertSent = true;
      for (int i = 0; i < 3; i++) blinkAlarm();
    } else {
      Serial.println("[DEBUG] Correction: no accident detected.");
      lcd.clear();
      lcd.print("Back to normal");
      accidentDetected = false;
    }
    return;
  }

  if (alertSent && !systemHalted) {
    systemHalted = true;
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Accident sent");
    lcd.setCursor(0, 1);
    lcd.print("Press to reset");
    Serial.println("===ALERT_SENT===");
  }

  if (systemHalted && !manualConfirmPressed()) {
    delay(50);
  } else if (systemHalted && manualConfirmPressed()) {
    systemHalted = false;
    Serial.println("[DEBUG] System reset by operator.");
  }

  delay(50);
}