"""HTTP smoke checks using native login, cookies and CSRF; no authentication bypass."""
from html import unescape
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import subprocess
import time
from urllib.error import URLError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor

assert os.environ.get('CI') == 'true', 'Disposable CI environment required'
repo = Path(__file__).resolve().parents[1]
root = repo / '.ci/dolibarr/htdocs'
fixture = json.loads((repo / '.ci/native-fixture.json').read_text())
base = 'http://127.0.0.1:8080'
class Inputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('name'):
            self.values[attrs['name']] = attrs.get('value', '')
def request(opener, path, fields=None):
    with opener.open(base + path, data=None if fields is None else urlencode(fields, doseq=True).encode(), timeout=20) as response:
        assert response.status == 200, path
        page = response.read().decode()
    assert not re.search(r'(Fatal error|Parse error|Uncaught [A-Za-z]+)', page), path + ': PHP failure'
    return page
with (repo / '.ci/http.log').open('w') as log:
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8080', '-t', str(root)], stdout=log, stderr=log)
    try:
        anonymous = build_opener(HTTPCookieProcessor(CookieJar()))
        for attempt in range(50):
            try:
                page = request(anonymous, '/index.php')
                break
            except URLError:
                if server.poll() is not None:
                    raise RuntimeError('PHP HTTP server failed')
                time.sleep(0.1)
        else:
            raise RuntimeError('PHP HTTP server did not start')
        parser = Inputs(); parser.feed(page)
        assert 'password' in parser.values and parser.values.get('token'), 'Native login and CSRF token missing'
        parser.values.update(username='admin', password='Training-CI-Only-84', tz_string='Europe/Copenhagen')
        page = request(anonymous, '/index.php', parser.values)
        assert 'name="password"' not in page, 'Native administrator login failed'
        paths = {
            f'/custom/training/course.php?id={fixture["product"]}': 'Kursus æøå',
            f'/custom/training/sessions.php?product_id={fixture["product"]}': 'NATIVE-CI',
            f'/custom/training/session.php?id={fixture["session"]}': 'NATIVE-CI',
            f'/custom/training/attendance.php?id={fixture["session"]}&slot_id={fixture["slot"]}': 'Participant',
            f'/custom/training/trainers.php?id={fixture["session"]}': 'NATIVE-CI',
            f'/custom/training/billing.php?id={fixture["session"]}': 'NATIVE-CI',
            '/custom/training/myattendance.php': 'NATIVE-CI',
            f'/custom/training/reservations.php?id={fixture["session"]}': 'Participant',
            f'/product/card.php?id={fixture["product"]}': f'/custom/training/course.php?id={fixture["product"]}',
        }
        for path, expected in paths.items():
            page = request(anonymous, path)
            assert 'name="password"' not in page and expected in unescape(page), f'{path}: expected rendered content missing'
            print('OK: authenticated native HTTP page', path)
        path = f'/custom/training/reservations.php?id={fixture["reservation_session"]}'
        page = request(anonymous, path)
        parser = Inputs(); parser.feed(page)
        assert parser.values.get('request_key') and parser.values.get('token'), 'Reservation form identity and CSRF missing'
        fields = dict(token=parser.values['token'], id=fixture['reservation_session'], action='reserve',
                      request_key=parser.values['request_key'])
        fields['contact_ids[]'] = [str(fixture['contact']), str(fixture['other_contact'])]
        page = request(anonymous, path, fields)
        assert 'Active reservation' in unescape(page) and 'Second participant' in page, 'Named group reservation form failed'
        repeated = request(anonymous, path, fields)
        assert unescape(repeated).count('Active reservation') == 1, 'Repeated form submit created another hold'
        parser = Inputs(); parser.feed(repeated)
        approval = dict(token=parser.values['token'], id=fixture['reservation_session'], action='confirmReservation',
                        hold_id=parser.values['hold_id'], reason='Native HTTP coordinator approval')
        page = request(anonymous, path, approval)
        assert '<td>Confirmed</td>' in unescape(page), 'Reservation approval form failed'
        page = request(anonymous, f'/custom/training/session.php?id={fixture["reservation_session"]}')
        assert 'Confirmed: 2' in unescape(page) and 'Reserved: 0' in unescape(page), 'Native HTTP conversion must replace reservation with two confirmed seats'
        print('OK: native reservation form, duplicate submit and whole-group approval')
        outsider = build_opener(HTTPCookieProcessor(CookieJar()))
        page = request(outsider, f'/custom/training/attendance.php?id={fixture["session"]}&slot_id={fixture["slot"]}')
        assert 'name="password"' in page and 'Participant' not in page, 'Anonymous access must require native login'
        print('OK: anonymous attendance access requires native login')
    finally:
        server.terminate()
        server.wait(timeout=10)
