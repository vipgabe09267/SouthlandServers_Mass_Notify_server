// Labs MKR WiFi 1010 / Nano 33 IoT isolated-contact example. Disabled by default.
// Optional client libraries: WiFiNINA, ArduinoBearSSL, FlashStorage.
// Use WiFiNINA's certificate uploader to trust the PBX issuer in the TLS module.
// The hostname must match the certificate. There is no insecure fallback.
#include <WiFiNINA.h>
#include <ArduinoBearSSL.h>
#include <FlashStorage.h>
#include <time.h>
static const bool ENABLE_SEND=false;
static const bool INITIALIZE_NEW_JOURNAL=false; // One reviewed enrollment only; then set false.
static const char *SSID="", *WIFI_PASSWORD="", *HOST="pbx.example.org";
static const char *RULE="trg_000000000000000000000000", *SECRET="";
static const char *DEVICE_ID="000000000000000000000001"; // Unique 24 hex digits.
static const int CONTACT_PIN=2;
struct Journal { uint32_t marker, sequence; char pending[512]; };
FlashStorage(savedJournal,Journal);
Journal journal;
bool clientReady=false;
bool candidate=false,stable=false,baseline=false;
unsigned long changedAt=0,lastActivation=0,lastHeartbeat=0;
String nextId() {
  // Persist the sequence before building a request. Do not reset this journal.
  if(journal.sequence==UINT32_MAX) return "";
  journal.sequence++; savedJournal.write(journal);
  char suffix[9]; sprintf(suffix,"%08lx",(unsigned long)journal.sequence);
  return String(DEVICE_ID)+suffix;
}
String makeBody(const char *operation,unsigned long now) {
  String id;
  if(String(operation)=="heartbeat") {
    // Heartbeat identities avoid wearing the flash journal once per minute.
    String seed=String(DEVICE_ID)+":heartbeat:"+String(now);
    br_sha256_context ctx; unsigned char digest[32]; br_sha256_init(&ctx);
    br_sha256_update(&ctx,seed.c_str(),seed.length()); br_sha256_out(&ctx,digest);
    char value[33]; for(int i=0;i<16;i++) sprintf(value+i*2,"%02x",digest[i]); value[32]=0;
    id=String(value);
  } else id=nextId();
  if(id.length()!=32) return "";
  String value="{\"operation\":\""+String(operation)+"\",\"request_id\":\""+id+"\",\"sent_at\":"+String(now)+",\"expires_at\":"+String(now+300)+",\"is_test\":true";
  if(String(operation)=="activate") value+=",\"event\":\"isolated-contact\",\"message\":\"Reviewed isolated dry contact changed\"";
  return value+"}";
}
bool post(const String &payload,unsigned long now) {
  if(!ENABLE_SEND || strlen(SECRET)!=64 || now<1700000000 || payload.length()==0 || payload.length()>511) return false;
  String stamp=String(now), bytes=String(RULE)+"."+stamp+"."+payload;
  br_hmac_key_context key; br_hmac_context mac; unsigned char digest[32];
  br_hmac_key_init(&key,&br_sha256_vtable,SECRET,strlen(SECRET));
  br_hmac_init(&mac,&key,0); br_hmac_update(&mac,bytes.c_str(),bytes.length()); br_hmac_out(&mac,digest);
  char signature[65]; for(int i=0;i<32;i++) sprintf(signature+i*2,"%02x",digest[i]); signature[64]=0;
  WiFiSSLClient tls;
  if(!tls.connect(HOST,443)) return false;
  tls.setTimeout(5000);
  tls.print("POST /api/sls-mass-notify/trigger.php?rule_id="); tls.print(RULE); tls.println(" HTTP/1.1");
  tls.print("Host: "); tls.println(HOST); tls.println("Connection: close"); tls.println("Content-Type: application/json");
  tls.print("X-SLS-Timestamp: "); tls.println(stamp); tls.print("X-SLS-Signature: "); tls.println(signature);
  tls.print("Content-Length: "); tls.println(payload.length()); tls.println(); tls.print(payload);
  String status=tls.readStringUntil('\n'); bool accepted=status.startsWith("HTTP/1.1 200 ") || status.startsWith("HTTP/1.0 200 ");
  // Never follow 30x redirects. Bound the whole response and recognize the exact success flag.
  String response; unsigned long deadline=millis()+5000;
  while((tls.connected() || tls.available()) && (long)(deadline-millis())>0) {
    if(tls.available()) { response+=(char)tls.read(); if(response.length()>8192) { accepted=false; break; } }
  }
  tls.stop(); return accepted && response.indexOf("\"ok\":true")>=0;
}
void setup() {
  pinMode(CONTACT_PIN,INPUT_PULLUP); Serial.begin(115200);
  if(!ENABLE_SEND) { Serial.println("Labs example disabled; no network requests"); return; }
  journal=savedJournal.read();
  if(journal.marker!=0x534c5301 && INITIALIZE_NEW_JOURNAL) { journal={0x534c5301,0,{0}}; savedJournal.write(journal); }
  if(journal.marker!=0x534c5301 || journal.pending[511]!=0) { Serial.println("Preserve corrupt journal for manual review"); return; }
  clientReady=true;
  WiFi.begin(SSID,WIFI_PASSWORD);
}
void loop() {
  if(!ENABLE_SEND || !clientReady) { delay(100); return; }
  unsigned long nowMs=millis(), epoch=WiFi.getTime(); bool input=digitalRead(CONTACT_PIN)==LOW;
  if(input!=candidate) { candidate=input; changedAt=nowMs; }
  if(nowMs-changedAt>=50 && (!baseline || candidate!=stable)) {
    bool was=stable; stable=candidate;
    if(baseline && !was && stable && journal.pending[0]==0 && nowMs-lastActivation>=10000 && epoch>=1700000000) {
      String payload=makeBody("activate",epoch); payload.toCharArray(journal.pending,sizeof journal.pending); savedJournal.write(journal); lastActivation=nowMs;
    } baseline=true;
  }
  if(WiFi.status()==WL_CONNECTED && epoch>=1700000000) {
    if(journal.pending[0]) { if(post(String(journal.pending),epoch)) { journal.pending[0]=0; savedJournal.write(journal); } delay(5000); }
    else if(nowMs-lastHeartbeat>=60000) { if(post(makeBody("heartbeat",epoch),epoch)) lastHeartbeat=nowMs; }
  }
  delay(10);
}
