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
static const unsigned int HTTPS_PORT=443;
static const char *RULE="trg_000000000000000000000000", *SECRET="";
static const char *DEVICE_ID="000000000000000000000001"; // Unique 24 hex digits.
static const int CONTACT_PIN=2;
struct Journal { uint32_t marker, sequence; char pending[512], expired[512]; uint32_t checksum; };
FlashStorage(savedJournal,Journal);
Journal journal;
bool clientReady=false;
bool candidate=false,stable=false,baseline=false;
unsigned long changedAt=0,lastActivation=0,lastHeartbeat=0,lastRetry=0;
uint32_t journalChecksum(const Journal &value) {
  uint32_t hash=2166136261UL;
  for(unsigned int i=0;i<4;i++) { hash=(hash^((value.marker>>(8*i))&255))*16777619UL; hash=(hash^((value.sequence>>(8*i))&255))*16777619UL; }
  for(unsigned int i=0;i<512;i++) { hash=(hash^(uint8_t)value.pending[i])*16777619UL; hash=(hash^(uint8_t)value.expired[i])*16777619UL; }
  return hash;
}
bool persist() {
  journal.checksum=journalChecksum(journal); savedJournal.write(journal);
  Journal after=savedJournal.read();
  if(memcmp(&after,&journal,sizeof journal)!=0) { clientReady=false; Serial.println("Journal persistence failed; preserve it for review"); return false; }
  return true;
}
unsigned long payloadExpiry(const String &payload) {
  const String key="\"expires_at\":"; int start=payload.indexOf(key);
  if(start<0) return 0;
  start+=key.length(); int end=payload.indexOf(',',start);
  if(end<0) return 0;
  String number=payload.substring(start,end);
  if(number.length()!=10) return 0;
  for(unsigned int i=0;i<number.length();i++) if(number[i]<'0'||number[i]>'9') return 0;
  unsigned long value=strtoul(number.c_str(),nullptr,10);
  return String(value)==number && value>=1700000000UL ? value : 0;
}
String nextId() {
  // Persist the sequence before building a request. Do not reset this journal.
  if(journal.sequence==UINT32_MAX) return "";
  journal.sequence++; if(!persist()) return "";
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
  if(!tls.connect(HOST,HTTPS_PORT)) return false;
  tls.setTimeout(5000);
  tls.print("POST /api/sls-mass-notify/trigger.php?rule_id="); tls.print(RULE); tls.println(" HTTP/1.1");
  tls.print("Host: "); tls.print(HOST); if(HTTPS_PORT!=443) { tls.print(":"); tls.print(HTTPS_PORT); } tls.println(); tls.println("Connection: close"); tls.println("Content-Type: application/json");
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
  if(journal.marker!=0x534c5302 && INITIALIZE_NEW_JOURNAL) { journal={0x534c5302,0,{0},{0},0}; if(!persist()) return; }
  if(journal.marker!=0x534c5302 || journal.pending[511]!=0 || journal.expired[511]!=0 || journal.checksum!=journalChecksum(journal)
     || (journal.pending[0] && payloadExpiry(String(journal.pending))==0)) { Serial.println("Preserve old or corrupt journal for manual review"); return; }
  clientReady=true;
  WiFi.begin(SSID,WIFI_PASSWORD);
}
void loop() {
  if(!ENABLE_SEND || !clientReady) { delay(100); return; }
  unsigned long nowMs=millis(), epoch=WiFi.getTime(); bool input=digitalRead(CONTACT_PIN)==LOW;
  if(journal.pending[0] && epoch>=1700000000 && payloadExpiry(String(journal.pending))<=epoch) {
    memcpy(journal.expired,journal.pending,sizeof journal.pending); memset(journal.pending,0,sizeof journal.pending);
    if(!persist()) return;
    Serial.println("Expired activation retained for review; automatic retry stopped");
  }
  if(input!=candidate) { candidate=input; changedAt=nowMs; }
  if(nowMs-changedAt>=50 && (!baseline || candidate!=stable)) {
    bool was=stable; stable=candidate;
    if(baseline && !was && stable && journal.pending[0]==0 && nowMs-lastActivation>=10000 && epoch>=1700000000) {
      String payload=makeBody("activate",epoch); if(payload.length()==0 || !clientReady) return;
      payload.toCharArray(journal.pending,sizeof journal.pending); if(!persist()) return; lastActivation=nowMs;
    } baseline=true;
  }
  if(WiFi.status()==WL_CONNECTED && epoch>=1700000000) {
    if(journal.pending[0] && nowMs-lastRetry>=5000) { lastRetry=nowMs; if(post(String(journal.pending),epoch)) { memset(journal.pending,0,sizeof journal.pending); if(!persist()) return; } }
    if(nowMs-lastHeartbeat>=60000) { lastHeartbeat=nowMs; post(makeBody("heartbeat",epoch),epoch); }
  }
  delay(10);
}
