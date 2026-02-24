#include <Servo.h>
#include "WiFiS3.h"

const char ssid[] = “ComLab506”;
const char pass[] = "#Ramswifi"; 
const char server[] = "192.168.52.135"; 
const int port = 80; 

const int trigPin  = 4;   
const int echoPin  = 2;
const int soundPin = A0;
const int greenLED = 13;
const int redLED   = 6;
const int buzzer   = 5;
const int servoPin = 12;
const int distanceThreshold = 15;
const int soundThreshold    = 100;
const unsigned long SEND_INTERVAL_MS = 2000; 

Servo myServo;
WiFiClient client;
bool locked = false;
String inputCode = "";
const String correctCode = "221";
unsigned long lastSend = 0;

void setup() {
  Serial.begin(115200);
  
  pinMode(trigPin, OUTPUT);
  pinMode(echoPin, INPUT);
  pinMode(soundPin, INPUT);
  pinMode(greenLED, OUTPUT);
  pinMode(redLED, OUTPUT);
  pinMode(buzzer, OUTPUT);

  myServo.attach(servoPin);
  unlockSystem();

  WiFi.begin(ssid, pass);
  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nConnected!");
  Serial.print("IP: ");
  Serial.println(WiFi.localIP());
}

void loop() {
  unsigned long now = millis();

  int distance  = getDistance();
  int soundVal = analogRead(soundPin);

  if (!locked && ((distance > 0 && distance <= distanceThreshold) || (soundVal >= soundThreshold))) {
    lockSystem();
  }

  if (Serial.available()) {
    inputCode = Serial.readStringUntil('\n');
    inputCode.trim();

    if (inputCode.length() == 3) {
      if (inputCode == correctCode && locked) {
        unlockSystem();
      } else if (locked) {
        wrongPasswordAlert();
      }
      inputCode = "";
    }
  }

  if (now - lastSend >= SEND_INTERVAL_MS) {
    lastSend = now;
    String lockStatusStr = locked ? "HARD-LOCK" : "UNLOCKED";
    String pirStatus = (distance > 0 && distance <= distanceThreshold) ? "MOTION" : "STABLE";
    
    sendToServer(distance, soundVal, pirStatus, lockStatusStr);
  }
}

int getDistance() {
  digitalWrite(trigPin, LOW);
  delayMicroseconds(2);
  digitalWrite(trigPin, HIGH);
  delayMicroseconds(10);
  digitalWrite(trigPin, LOW);

  long duration = pulseIn(echoPin, HIGH, 30000);
  if (duration == 0) return -1;
  return (int)(duration * 0.034 / 2);
}

void lockSystem() {
  myServo.write(90);
  digitalWrite(redLED, HIGH);
  digitalWrite(greenLED, LOW);
  digitalWrite(buzzer, HIGH); 
  locked = true;
  Serial.println("SYSTEM LOCKED");
}

void unlockSystem() {
  myServo.write(0);
  digitalWrite(greenLED, HIGH);
  digitalWrite(redLED, LOW);
  digitalWrite(buzzer, LOW);
  locked = false;
  Serial.println("SYSTEM UNLOCKED");
}

void wrongPasswordAlert() {
  digitalWrite(buzzer, HIGH);
  delay(500);
  digitalWrite(buzzer, LOW);
  if(locked) digitalWrite(buzzer, HIGH); 
}

void sendToServer(int distance, int soundVal, String pirStatus, String lockState) {
  if (!client.connect(server, port)) {
    Serial.println("Server connection failed");
    return;
  }

  String path = "/fProject_PEMBEDS%202/BackEnd/sensor_update.php"; 
  String payload = "{\"device_id\":1,\"distance\":" + String(distance) + 
                   ",\"sound_db\":" + String(soundVal) + 
                   ",\"pir_status\":\"" + pirStatus + "\"" +
                   ",\"lock_state\":\"" + lockState + "\"}";

  client.print(String("POST ") + path + " HTTP/1.1\r\n");
  client.print(String("Host: ") + server + "\r\n");
  client.print("Content-Type: application/json\r\n");
  client.print("Content-Length: " + String(payload.length()) + "\r\n");
  client.print("Connection: close\r\n\r\n");
  client.print(payload);

  while (client.available()) { client.read(); }
  client.stop();
}