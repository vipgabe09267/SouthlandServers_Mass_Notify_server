#!/usr/bin/python3
import unittest
from check_dependencies import audit

class AdvisoryTests(unittest.TestCase):
    def test_embedded_package_and_pagination_are_checked(self):
        bom={'components':[{'purl':'pkg:npm/chart.js@2.9.4'}, {'purl':'pkg:npm/moment@2.24.0'}]}
        calls=[]
        def transport(value):
            calls.append(value)
            if len(calls)==1:return {'results':[{}, {'vulns':[{'id':'GHSA-example'}], 'next_page_token':'next'}]}
            return {'results':[{'vulns':[{'id':'GHSA-second'}]}]}
        result=audit(bom, transport)
        self.assertEqual(result['components_checked'], 2)
        self.assertEqual(result['findings'][0]['advisories'], ['GHSA-example','GHSA-second'])
        self.assertEqual(calls[1]['queries'][0]['page_token'], 'next')
    def test_unavailable_or_incomplete_response_is_not_a_clean_scan(self):
        bom={'components':[{'purl':'pkg:npm/chart.js@2.9.4'}]}
        with self.assertRaises(ValueError):audit(bom, lambda _: {'results':[]})
        with self.assertRaises(ValueError):audit(bom, lambda _: {'results':[{'vulns':[{'id':None}]}]})

if __name__=='__main__':unittest.main()
