// Labs example: Arduino-ESP32 + approved isolated GPIO input. Disabled by default.
// No fire panel wiring, control output, discovery or insecure TLS mode.
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <mbedtls/md.h>
#include <time.h>
static const bool ENABLE_SEND = false;
static const char *SSID = "", *WIFI_PASSWORD = "";
static const char *URL = "https://pbx.example.org/api/sls-mass-notify/trigger.php?rule_id=trg_000000000000000000000000";
static const char *RULE = "trg_000000000000000000000000";
static const char *SECRET = ""; // Literal 64-character enrollment secret, not hex-decoded.
static const char *ROOT_CA = ""; // PBX certificate issuer PEM; never use setInsecure().
static const int CONTACT_PIN = 27;
Preferences journal;
String pending;
bool candidate = false, stable = false, baseline = false;
unsigned long changedAt = 0, lastHeartbeat = 0, lastActivation = 0;
String newId() {
  char buffer[33]; uint8_t bytes[16]; esp_fill_random(bytes, sizeof bytes);
  for (int i=0;i<16;i++) sprintf(buffer+i*2,"%02x",bytes[i]); buffer[32]=0; return String(buffer);
}
String body(const char *operation, time_t now) {
  String value="{\"operation\":\""+String(operation)+"\",\"request_id\":\""+newId()+"\",\"sent_at\":"+String((unsigned long)now)+",\"expires_at\":"+String((unsigned long)now+300)+",\"is_test\":true";
  if (String(operation)=="activate") value+=",\"event\":\"isolated-contact\",\"message\":\"Reviewed isolated dry contact changed\"";
  return value+"}";
}
bool post(const String &payload) {
  time_t now=time(nullptr); if (!ENABLE_SEND || now<1700000000 || strlen(SECRET)!=64 || strlen(ROOT_CA)==0) return false;
  String stamp=String((unsigned long)now), bytes=String(RULE)+"."+stamp+"."+payload;
  uint8_t digest[32]; mbedtls_md_context_t ctx; mbedtls_md_init(&ctx);
  if (mbedtls_md_setup(&ctx,mbedtls_md_info_from_type(MBEDTLS_MD_SHA256),1)!=0) return false;
  mbedtls_md_hmac_starts(&ctx,(const unsigned char*)SECRET,strlen(SECRET));
  mbedtls_md_hmac_update(&ctx,(const unsigned char*)bytes.c_str(),bytes.length());
  mbedtls_md_hmac_finish(&ctx,digest); mbedtls_md_free(&ctx);
  char signature[65]; for(int i=0;i<32;i++) sprintf(signature+i*2,"%02x",digest[i]); signature[64]=0;
  WiFiClientSecure tls; tls.setCACert(ROOT_CA); HTTPClient http;
  if(!http.begin(tls,URL)) return false;
  http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS); http.setTimeout(5000);
  http.addHeader("Content-Type","application/json"); http.addHeader("X-SLS-Timestamp",stamp); http.addHeader("X-SLS-Signature",signature);
  int code=http.POST(payload); String reply;
  WiFiClient *stream=http.getStreamPtr(); unsigned long deadline=millis()+5000;
  while((http.connected() || stream->available()) && (long)(deadline-millis())>0) {
    if(stream->available()) { reply+=(char)stream->read(); if(reply.length()>8192) break; }
    else delay(1);
  }
  http.end();
  return code==200 && reply.length()<=8192 && reply.indexOf("\"ok\":true")>=0;
}
void setup() {
  pinMode(CONTACT_PIN,INPUT_PULLUP); Serial.begin(115200);
  if(!ENABLE_SEND) { Serial.println("Labs example disabled; no network requests"); return; }
  journal.begin("sls-sensor",false); pending=journal.getString("pending","");
  WiFi.begin(SSID,WIFI_PASSWORD); configTime(0,0,"pool.ntp.org");
}
void loop() {
  if(!ENABLE_SEND) { delay(100); return; }
  unsigned long nowMs=millis(); bool input=digitalRead(CONTACT_PIN)==LOW;
  if(input!=candidate) { candidate=input; changedAt=nowMs; }
  if(nowMs-changedAt>=50 && (!baseline || candidate!=stable)) {
    bool was=stable; stable=candidate;
    if(baseline && !was && stable && pending.length()==0 && nowMs-lastActivation>=10000 && time(nullptr)>=1700000000) {
      pending=body("activate",time(nullptr)); journal.putString("pending",pending); lastActivation=nowMs;
    } baseline=true;
  }
  if(WiFi.status()==WL_CONNECTED && time(nullptr)>=1700000000) {
    if(pending.length()) { if(post(pending)) { pending=""; journal.remove("pending"); } delay(5000); }
    else if(nowMs-lastHeartbeat>=60000) { if(post(body("heartbeat",time(nullptr)))) lastHeartbeat=nowMs; }
  }
  delay(10);
}
