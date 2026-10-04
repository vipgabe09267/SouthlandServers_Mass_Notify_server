#!/usr/bin/python3
"""Official Tempest fields and discovered Meteomatics GML, using local transports."""
import json
from pathlib import Path
import sys
import unittest
import urllib.parse

sys.dont_write_bytecode=True
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'slsmassnotifyserver/bin/sls_mass_notify'))
import sls_lightning_providers as provider

class Response:
    status=200
    def __init__(self,body):self.body=body
    def __enter__(self):return self
    def __exit__(self,*args):pass
    def read(self,size):return self.body[:size]
class Transport:
    def __init__(self,*bodies):self.bodies=list(bodies);self.requests=[]
    def open(self,request,timeout):self.requests.append(request);return Response(self.bodies.pop(0))

SCHEMA=b'<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:element name="time" type="xsd:dateTime"/><xsd:element name="type" type="xsd:int"/></xsd:schema>'
GML=b'<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs" xmlns:gml="http://www.opengis.net/gml"><gml:featureMember><lightning><time>2026-10-02T12:00:00Z</time><type>2</type><gml:Point><gml:coordinates>-97.0,30.0</gml:coordinates></gml:Point></lightning></gml:featureMember></wfs:FeatureCollection>'

class Providers(unittest.TestCase):
    def settings(self,name):return {'provider':name,'location':'30.0,-97.0','radius_miles':25,'client_id':'fixture-user','client_secret':'fixture-secret','strike_type':'both'}
    def test_tempest_documented_contract_and_meter_query(self):
        transport=Transport(json.dumps({'strikes':[{'time':1790942400,'lat':30.0,'lon':-97.0,'strike_type':'cg'}]}).encode())
        result=provider.fetch(self.settings('tempest'),opener=transport)
        self.assertEqual(result['response'][0]['ob']['pulse']['type'],'cg')
        self.assertEqual(result['response'][0]['relativeTo']['distanceMI'],0)
        query=urllib.parse.parse_qs(urllib.parse.urlsplit(transport.requests[0].full_url).query)
        self.assertEqual(float(query['radius'][0]),25*1609.344);self.assertEqual(query['api_key'],['fixture-user'])

    def test_meteomatics_schema_discovery_basic_auth_and_coordinates(self):
        transport=Transport(SCHEMA,GML);result=provider.fetch(self.settings('meteomatics'),opener=transport)
        self.assertEqual(result['response'][0]['ob']['timestamp'],1790942400)
        self.assertTrue(all(req.get_header('Authorization').startswith('Basic ') for req in transport.requests))
        self.assertTrue(all(urllib.parse.urlsplit(req.full_url).hostname=='api.meteomatics.com' for req in transport.requests))

    def test_empty_valid_observations(self):
        self.assertEqual(provider.fetch(self.settings('tempest'),opener=Transport(b'{"strikes":[]}'))['response'],[])
        self.assertEqual(provider.fetch(self.settings('meteomatics'),opener=Transport(SCHEMA,b'<FeatureCollection/>'))['response'],[])

    def test_unknown_schema_strike_type_or_timezone_never_implies_clear(self):
        for bodies in [(b'<schema/>',),(SCHEMA,GML.replace(b'<type>2</type>',b'<type>-999</type>')),(SCHEMA,GML.replace(b'12:00:00Z',b'12:00:00'))]:
            with self.assertRaises(ValueError):provider.fetch(self.settings('meteomatics'),opener=Transport(*bodies))

    def test_invalid_tempest_payload_and_overflow_rejected(self):
        for payload in ({'error':'unauthorized'},{'strikes':[None]},{'strikes':[{'time':True,'lat':30,'lon':-97,'strike_type':'cg'}]}):
            with self.assertRaises(ValueError):provider.fetch(self.settings('tempest'),opener=Transport(json.dumps(payload).encode()))
        with self.assertRaises(ValueError):provider.normalized('tempest',(30,-97),25,[(1790942400,30,-97,'cg')]*provider.MAX_STRIKES)

    def test_xml_entities_oversized_and_redirect_rejected(self):
        with self.assertRaises(ValueError):provider.xml(b'<!DOCTYPE a [<!ENTITY test SYSTEM "file:///etc/passwd">]><a>&test;</a>')
        with self.assertRaises(ValueError):provider.request('https://api.meteomatics.com/wfs',opener=Transport(b'x'*(provider.MAX_BYTES+1)))
        with self.assertRaises(ValueError):provider.NoRedirect().redirect_request(None,None,None,None,None,None)

    def test_errors_do_not_expose_credentials(self):
        class Broken:
            def open(self,*args,**kwargs):raise RuntimeError('fixture-secret https://evil.invalid')
        with self.assertRaises(ValueError) as error:provider.fetch(self.settings('tempest'),opener=Broken())
        self.assertNotIn('fixture-secret',str(error.exception));self.assertNotIn('evil.invalid',str(error.exception))

    def test_invalid_location_and_coordinates_rejected(self):
        for value in ('city','90,20','30,181','30,20&api_key=stolen'):
            with self.assertRaises(ValueError):provider.coordinates(value)
        with self.assertRaises(ValueError):provider.normalized('tempest',(30,-97),25,[(1790942400,float('nan'),-97,'cg')])

if __name__=='__main__':unittest.main()
