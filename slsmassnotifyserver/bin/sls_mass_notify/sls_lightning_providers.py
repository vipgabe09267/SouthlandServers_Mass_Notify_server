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
import time
import re
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

PROVIDERS = {'xweather':'Xweather', 'tempest':'Tempest', 'meteomatics':'Meteomatics'}
MAX_BYTES = 8*1024*1024
MAX_STRIKES = 10000

def json_payload(body):
    def object_pairs(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('lightning_provider_duplicate_json_key')
            result[key] = value
        return result

    def finite_number(raw):
        value = float(raw)
        if not math.isfinite(value):
            raise ValueError('lightning_provider_nonfinite_json')
        return value

    def invalid_constant(_raw):
        raise ValueError('lightning_provider_nonfinite_json')

    try:
        return json.loads(body, object_pairs_hook=object_pairs,
                          parse_float=finite_number, parse_constant=invalid_constant)
    except (UnicodeError, json.JSONDecodeError, RecursionError):
        raise ValueError('lightning_provider_invalid_json') from None

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
    req=urllib.request.Request(url,headers={'User-Agent':'SLS-Mass-Notify/0.1.7-beta', 'Accept':'application/json, application/xml', **(headers or {})})
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
        if type(lat) not in (int, float) or type(lon) not in (int, float):
            raise ValueError('lightning_provider_invalid_strike_coordinates')
        miles=distance(center,float(lat),float(lon))
        if miles > radius: continue
        token=hashlib.sha256(f'{provider}|{timestamp}|{lat}|{lon}|{kind}'.encode()).hexdigest()[:32]
        output.append({'id':provider+':'+token,'ob':{'timestamp':timestamp,'pulse':{'type':kind}},'relativeTo':{'distanceMI':miles}})
    return {'success':True,'response':output}

def circle_bounds(center, radius):
    angle = radius / 3958.7613
    latitude, longitude = map(math.radians, center)
    south = max(-math.pi / 2, latitude - angle)
    north = min(math.pi / 2, latitude + angle)
    low, high = math.degrees(south), math.degrees(north)
    if south <= -math.pi / 2 or north >= math.pi / 2:
        return [(-180.0, low, 180.0, high)]
    span = math.degrees(math.asin(min(1.0, math.sin(angle) / math.cos(latitude))))
    west, east = center[1] - span, center[1] + span
    if west < -180:
        return [(west + 360, low, 180.0, high), (-180.0, low, east, high)]
    if east > 180:
        return [(west, low, 180.0, high), (-180.0, low, east - 360, high)]
    return [(west, low, east, high)]


def fetch(settings, *, opener=None):
    provider=settings.get('provider','xweather'); center=coordinates(settings.get('location',''))
    radius=float(settings['radius_miles']); kind={'cloud_to_ground':'cg','cloud_to_cloud':'ic','both':'all'}[settings.get('strike_type','cloud_to_ground')]
    if not math.isfinite(radius) or not 1<=radius<=62: raise ValueError('Lightning radius must be from 1 through 62 miles.')
    now=settings.get('_window_end_at',int(datetime.now(timezone.utc).timestamp()))
    lookback=settings.get('_lookback_seconds',300)
    if type(now) is not int or not 0<now<=253402300799 or type(lookback) is not int or lookback<=0:
        raise ValueError('lightning_observation_window_invalid')
    if provider=='tempest':
        started=time.monotonic()
        minutes=min(60,max(5,math.ceil(lookback/60)))
        query=urllib.parse.urlencode({'lat':center[0],'lon':center[1],'radius':radius*1609.344,'minutes_offset':minutes,'strike_type':kind,'api_key':settings['client_id']})
        payload=json_payload(request('https://swd.weatherflow.com/swd/rest/lightning?'+query,opener=opener))
        if not isinstance(payload,dict) or not isinstance(payload.get('strikes'),list): raise ValueError('Tempest did not return its documented strikes array. Verify paid Lightning API access.')
        values=[]
        for row in payload['strikes']:
            if not isinstance(row,dict) or not all(k in row for k in ('time','lat','lon','strike_type')): raise ValueError('tempest_strike_contract_mismatch')
            values.append((row['time'],row['lat'],row['lon'],row['strike_type']))
        result=normalized(provider,center,radius,values)
        result['observation_window']={'start':now-minutes*60+math.ceil(time.monotonic()-started),'end':now}
        return result
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
    windows=min(3,max(1,math.ceil(lookback/300)))
    values=[]
    for offset in range(windows):
        for bounds in circle_bounds(center,radius):
            query={**common,'REQUEST':'GetFeature','BBOX':','.join(str(v) for v in bounds),
                   'MAXFEATURES':MAX_STRIKES,'TIME':datetime.fromtimestamp(now-offset*300,timezone.utc).isoformat().replace('+00:00','Z')}
            values.extend(meteomatics_features(xml(request(endpoint+urllib.parse.urlencode(query),headers,opener)),times[0],kinds[0]))
            if len(values)>=MAX_STRIKES: raise ValueError('Meteomatics observation reached its bounded strike limit; all-clear is unavailable.')
    values=list(dict.fromkeys(values))
    result=normalized(provider,center,radius,values)
    result['observation_window']={'start':now-windows*300,'end':now}
    return result


def meteomatics_features(tree,time_field,kind_field):
    gml='{http://www.opengis.net/gml}'
    if tree.tag.split('}')[-1] != 'FeatureCollection': raise ValueError('meteomatics_feature_collection_missing')
    if any(element.tag not in {gml+'boundedBy',gml+'featureMember'} for element in tree):
        raise ValueError('meteomatics_unsupported_feature_collection_members')
    values=[]
    for member in tree.findall(gml+'featureMember'):
        if len(member)!=1: raise ValueError('meteomatics_invalid_feature')
        feature=member[0];fields={}
        for element in feature:
            name=element.tag.split('}')[-1]
            if name in fields: raise ValueError('meteomatics_duplicate_strike_field')
            fields[name]=element.text
        points=list(feature.iter(gml+'coordinates'))
        if len(points)!=1 or time_field not in fields or kind_field not in fields: raise ValueError('meteomatics_feature_contract_mismatch')
        try:
            lon,lat=map(float,points[0].text.strip().split(','));at=datetime.fromisoformat(fields[time_field].replace('Z','+00:00'))
            if at.tzinfo is None: raise ValueError('missing_timezone')
            pulse={1:'ic',2:'cg'}.get(int(fields[kind_field]))
        except (TypeError,ValueError,AttributeError): raise ValueError('meteomatics_invalid_strike_fields') from None
        if pulse is None: raise ValueError('Meteomatics returned an unclassified lightning strike; no all-clear can be inferred.')
        values.append((int(at.timestamp()),lat,lon,pulse))
    return values
