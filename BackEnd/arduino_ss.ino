#include "WiFiS3.h"

const char ssid[] = "";
const char pass[] = ""; 
const char server[] = ""; //put ip address, find ip address using ipconfig or ipconfig getifaddr en0in cmd
const int port = 80; 

const int trigPin  = 3;
const int echoPin  = 2;
const int soundPin = A0;

const int distanceThreshold = 15;
const int soundThreshold    = 100;

WiFiClient client;

unsigned long lastSend = 0;
const unsigned long SEND_INTERVAL_MS = 2000; 

int getDistance() {
  digitalWrite(trigPin, LOW);
  delayMicroseconds(2);
  digitalWrite(trigPin, HIGH);
  delayMicroseconds(10);
  digitalWrite(trigPin, LOW);

  long duration = pulseIn(echoPin, HIGH, 30000);
  if (duration == 0) return -1;
  int dist = (int)(duration * 0.034 / 2);
  return dist;
}

void setup() {
  Serial.begin(115200);
  pinMode(trigPin, OUTPUT);
  pinMode(echoPin, INPUT);
  pinMode(soundPin, INPUT);
  WiFi.begin(ssid, pass);
  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  Serial.print("Connected! IP: ");
  Serial.println(WiFi.localIP());
}

void loop() {
  unsigned long now = millis();

  int distance = getDistance();
  int soundVal = analogRead(soundPin);

  String pirStatus = "STABLE"; // we do not have pir btw so ignore this
  if (distance > 0 && distance <= distanceThreshold) pirStatus = "MOTION";
  String lockState = "UNLOCKED";
  if ((distance > 0 && distance <= distanceThreshold) || soundVal >= soundThreshold) {
    lockState = "HARD-LOCK";
  }

  if (now - lastSend >= SEND_INTERVAL_MS) {
    lastSend = now;
    sendToServer(distance, soundVal, pirStatus, lockState);
  }

  delay(100);
}

void sendToServer(int distance, int soundVal, String pirStatus, String lockState) {
  if (!client.connect(server, port)) {
    Serial.println("Connection to server failed");
    return;
  }

  
  String path = "/fProject_PEMBEDS%202/BackEnd/sensor_update.php"; 
  // prefer sending JSON
  String payload = "{\"device_id\":1,\"distance\":";
  payload += String(distance);
  payload += ",\"sound_db\":";
  payload += String(soundVal);
  payload += ",\"pir_status\":\"" + pirStatus + "\"";
  payload += ",\"lock_state\":\"" + lockState + "\"}";

  client.print(String("POST ") + path + " HTTP/1.1\r\n");
  client.print(String("Host: ") + server + "\r\n");
  client.print("Content-Type: application/json\r\n");
  client.print("Content-Length: " + String(payload.length()) + "\r\n");
  client.print("Connection: close\r\n");
  client.print("\r\n");
  client.print(payload);

  // read response
  unsigned long timeout = millis();
  while (client.available() == 0) {
    if (millis() - timeout > 3000) {
      Serial.println(">>> Client Timeout !");
      client.stop();
      return;
    }
  }

  while (client.available()) {
    String line = client.readStringUntil('\n');
    Serial.println(line);
  }

  client.stop();
}