#!/usr/bin/env python3
"""Convert the pinned public-domain Natural Earth land file into inert SVG.

No downloads. Supply the reviewed ne_110m_land.geojson file as argv[1].
The output contains only finite numeric path coordinates and fixed markup.
"""
import hashlib
import json
import math
from pathlib import Path
import sys

SOURCE_SHA256 = "9e0729ee253ca7d7a5c4ae9395fb1902264c5377c52e224d13dd85010e2835d9"


def convert(body):
    if len(body) > 2000000 or hashlib.sha256(body).hexdigest() != SOURCE_SHA256:
        raise ValueError("Basemap source differs from the reviewed Natural Earth file")
    data = json.loads(body)
    if data.get("type") != "FeatureCollection":
        raise ValueError("Expected GeoJSON FeatureCollection")
    paths = []
    for feature in data["features"]:
        geometry = feature["geometry"]
        if geometry["type"] != "Polygon":
            raise ValueError("Unexpected land geometry")
        rings = []
        for ring in geometry["coordinates"]:
            points = []
            for longitude, latitude in ring:
                if not (math.isfinite(longitude) and math.isfinite(latitude) and -180 <= longitude <= 180 and -90 <= latitude <= 90):
                    raise ValueError("Invalid coordinate")
                points.append(f"{longitude+180:.6f},{90-latitude:.6f}")
            rings.append("M" + "L".join(points) + "Z")
        paths.append('<path d="' + "".join(rings) + '"/>')
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 360 180">'
            '<title>Natural Earth land overview</title>'
            '<g fill="#d7e2dc" fill-rule="evenodd" stroke="#a7bab0" stroke-width="0.07">'
            + "".join(paths) + '</g></svg>\n').encode("ascii")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: build_location_basemap.py reviewed.geojson output.svg")
    with Path(sys.argv[1]).open("rb") as source:
        output = convert(source.read(2000001))
    Path(sys.argv[2]).write_bytes(output)
    print(hashlib.sha256(output).hexdigest())
