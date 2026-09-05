import importlib.util
import os
import pathlib
import tempfile
import time

path = pathlib.Path(os.environ.get('SLS_LIGHTNING_SOURCE', str(pathlib.Path(__file__).resolve().parents[1] / 'slsmassnotifyserver/bin/sls_mass_notify/sls_mass_notify_xweather_poll.py')))
spec = importlib.util.spec_from_file_location('lightning_audit', path)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
for response in (None, 'broken', {'unexpected': 'shape'}, [None], [{'id': 'missing-fields'}]):
    try:
        m.normalize_records({'success': True, 'response': response})
    except ValueError:
        pass
    else:
        raise AssertionError(f'Invalid response accepted: {response!r}')
assert m.normalize_records({'success': True, 'response': []}) == []
record = {'id': 'valid', 'ob': {'timestamp': int(time.time()), 'pulse': {'type': 'cg'}}, 'relativeTo': {'distanceMI': 3.1}}
assert len(m.normalize_records({'success': True, 'response': [record]})) == 1
try:
    m._forecast_indicates_thunder({'properties': {}}, 1000, 3600)
except RuntimeError:
    pass
else:
    raise AssertionError('Missing forecast observations accepted')
with tempfile.TemporaryDirectory() as directory:
    m.DATA_DIR = pathlib.Path(directory)
    m.CURRENT_GROUP_ID = 'unchanged-id'
    old = {'location': '30,-97', 'radius_miles': 10}
    new = {'location': '40,-100', 'radius_miles': 10}
    m.read_json_object = lambda p: {'configuration_identity': m.lightning_area_identity({}, old), 'checked_at': 999, 'expires_at': 1100, 'active': True, 'grid_url': 'old-grid'}
    requested = []
    def fake_nws(url, **kwargs):
        requested.append(url)
        if '/points/' in url:
            return {'properties': {'forecastGridData': 'https://api.weather.gov/gridpoints/NEW/1,1'}}
        return {'properties': {'weather': {'values': []}}}
    m._nws_json = fake_nws
    result = m.forecast_storm_gate({}, new, 1000, 60)
    assert not result[0]
    assert requested[0].endswith('/40.0000,-100.0000'), requested
assert 'outside' not in m.build_spoken_message('clear', False, 10, 'this area').lower()
print('Lightning schema, area-cache identity, forecast validation and all-clear wording checks passed.')
