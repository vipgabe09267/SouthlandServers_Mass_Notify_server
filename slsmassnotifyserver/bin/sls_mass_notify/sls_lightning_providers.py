#!/usr/bin/python3
"""Additional lightning observations, normalized to the existing strike contract.

Tempest: official /lightning OpenAPI (time/lat/lon/strike_type).
Meteomatics: WFS 1.0 GML; date/type properties are discovered from the provider's
authenticated DescribeFeatureType schema, never guessed from alert wording.
"""
import base64
from datetime import datetime, timezone
import hashlib
import json
import math
import re
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

PROVIDERS = {'xweather':'Xweather', 'tempest':'Tempest', 'meteomatics':'Meteomatics'}
MAX_BYTES = 8*1024*1024
MAX_STRIKES = 10000

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise ValueError('lightning_provider_redirect_blocked')

def coordinates(value):
    if not isinstance(value, str) or not re.fullmatch(r'\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?\s*', value):
        raise ValueError('This lightning provider needs latitude,longitude coordinates for each area.')
    lat, lon = map(float, value.split(','))
    if not -90 < lat < 90 or not -180 <= lon <= 180:
        raise ValueError('Lightning area coordinates are outside valid latitude/longitude ranges.')
    return lat, lon

def distance(center, lat, lon):
    if not all(math.isfinite(v) for v in (lat,lon)) or not -90 <= lat <= 90 or not -180 <= lon <= 180:
        raise ValueError('lightning_provider_invalid_strike_coordinates')
    a,b=map(math.radians,center);c,d=map(math.radians,(lat,lon))
    h=math.sin((c-a)/2)**2+math.cos(a)*math.cos(c)*math.sin((d-b)/2)**2
    return 3958.7613*2*math.asin(math.sqrt(min(1,max(0,h))))

def request(url, headers=None, opener=None):
    client = opener or urllib.request.build_opener(NoRedirect())
    req=urllib.request.Request(url,headers={'User-Agent':'SLS-Mass-Notify/0.1.5-beta', 'Accept':'application/json, application/xml', **(headers or {})})
    # Transport errors must not expose credential-bearing URLs or headers.
    try:
        with client.open(req,timeout=20) as response:
            if response.status != 200: raise ValueError('provider_http_'+str(response.status))
            body=response.read(MAX_BYTES+1)
    except Exception as error:
        if isinstance(error, ValueError): raise
        raise ValueError('Lightning provider request failed. Check credentials, subscription, HTTPS connectivity and provider status.') from None
    if len(body)>MAX_BYTES: raise ValueError('lightning_provider_response_too_large')
    return body

def xml(body):
    if b'<!DOCTYPE' in body.upper() or b'<!ENTITY' in body.upper(): raise ValueError('lightning_provider_xml_entities_rejected')
    try: return ET.fromstring(body)
    except ET.ParseError: raise ValueError('lightning_provider_invalid_xml') from None

def normalized(provider, center, radius, values):
    if len(values) >= MAX_STRIKES: raise ValueError('Lightning strike response reached its limit; no all-clear can be inferred. Reduce the area radius.')
    output=[]
    for timestamp,lat,lon,kind in values:
        if type(timestamp) is not int or timestamp <= 0 or kind not in {'cg','ic'}: raise ValueError('lightning_provider_invalid_strike')
        miles=distance(center,float(lat),float(lon))
        if miles > radius: continue
        token=hashlib.sha256(f'{provider}|{timestamp}|{lat}|{lon}|{kind}'.encode()).hexdigest()[:32]
        output.append({'id':provider+':'+token,'ob':{'timestamp':timestamp,'pulse':{'type':kind}},'relativeTo':{'distanceMI':miles}})
    return {'success':True,'response':output}

def fetch(settings, *, opener=None):
    provider=settings.get('provider','xweather'); center=coordinates(settings.get('location',''))
    radius=float(settings['radius_miles']); kind={'cloud_to_ground':'cg','cloud_to_cloud':'ic','both':'all'}[settings.get('strike_type','cloud_to_ground')]
    if provider=='tempest':
        query=urllib.parse.urlencode({'lat':center[0],'lon':center[1],'radius':radius*1609.344,'minutes_offset':5,'strike_type':kind,'api_key':settings['client_id']})
        try: payload=json.loads(request('https://swd.weatherflow.com/swd/rest/lightning?'+query,opener=opener))
        except (UnicodeError,json.JSONDecodeError): raise ValueError('tempest_invalid_json') from None
        if not isinstance(payload,dict) or not isinstance(payload.get('strikes'),list): raise ValueError('Tempest did not return its documented strikes array. Verify paid Lightning API access.')
        values=[]
        for row in payload['strikes']:
            if not isinstance(row,dict) or not all(k in row for k in ('time','lat','lon','strike_type')): raise ValueError('tempest_strike_contract_mismatch')
            values.append((row['time'],row['lat'],row['lon'],row['strike_type']))
        return normalized(provider,center,radius,values)
    if provider!='meteomatics': raise ValueError('Select a supported lightning provider.')
    auth=base64.b64encode((settings['client_id']+':'+settings['client_secret']).encode()).decode()
    headers={'Authorization':'Basic '+auth}
    endpoint='https://api.meteomatics.com/wfs?'
    common={'SERVICE':'WFS','VERSION':'1.0.0','TYPENAME':'lightnings'}
    schema=xml(request(endpoint+urllib.parse.urlencode({**common,'REQUEST':'DescribeFeatureType'}),headers,opener))
    xsd='{http://www.w3.org/2001/XMLSchema}'
    properties=[element for element in schema.iter(xsd+'element') if element.get('name') and element.get('type')]
    times=[e.get('name') for e in properties if e.get('type','').split(':')[-1]=='dateTime']
    kinds=[e.get('name') for e in properties if e.get('type','').split(':')[-1] in {'int','integer','short','byte'}]
    if len(times)!=1 or len(kinds)!=1: raise ValueError('Meteomatics Lightning schema does not expose one strike time and type. Provider contract validation is required before enabling this integration.')
    dy=radius/69.0;dx=dy/max(.01,math.cos(math.radians(center[0])))
    if center[1]-dx < -180 or center[1]+dx > 180: raise ValueError('Meteomatics areas crossing the date line need a smaller radius.')
    bbox=','.join(str(v) for v in (center[1]-dx,max(-90,center[0]-dy),center[1]+dx,min(90,center[0]+dy)))
    query={**common,'REQUEST':'GetFeature','BBOX':bbox,'MAXFEATURES':MAX_STRIKES,'TIME':datetime.now(timezone.utc).isoformat().replace('+00:00','Z')}
    tree=xml(request(endpoint+urllib.parse.urlencode(query),headers,opener));gml='{http://www.opengis.net/gml}'
    if tree.tag.split('}')[-1] != 'FeatureCollection': raise ValueError('meteomatics_feature_collection_missing')
    if any(element.tag not in {gml+'boundedBy',gml+'featureMember'} for element in tree):
        raise ValueError('meteomatics_unsupported_feature_collection_members')
    values=[]
    for member in tree.findall(gml+'featureMember'):
        if len(member)!=1: raise ValueError('meteomatics_invalid_feature')
        feature=member[0];fields={element.tag.split('}')[-1]:element.text for element in feature}
        points=list(feature.iter(gml+'coordinates'))
        if len(points)!=1 or times[0] not in fields or kinds[0] not in fields: raise ValueError('meteomatics_feature_contract_mismatch')
        try:
            lon,lat=map(float,points[0].text.strip().split(','));at=datetime.fromisoformat(fields[times[0]].replace('Z','+00:00'))
            if at.tzinfo is None: raise ValueError('missing_timezone')
            pulse={1:'ic',2:'cg'}.get(int(fields[kinds[0]]))
        except (TypeError,ValueError,AttributeError): raise ValueError('meteomatics_invalid_strike_fields') from None
        if pulse is None: raise ValueError('Meteomatics returned an unclassified lightning strike; no all-clear can be inferred.')
        values.append((int(at.timestamp()),lat,lon,pulse))
    return normalized(provider,center,radius,values)
