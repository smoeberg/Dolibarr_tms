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
from urllib.error import URLError, HTTPError
from urllib.parse import urlencode, urljoin, urlparse, parse_qs
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler

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
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
def request(opener, path, fields=None):
    target = path if path.startswith('http://') or path.startswith('https://') else base + path
    with opener.open(target, data=None if fields is None else urlencode(fields, doseq=True).encode(), timeout=20) as response:
        assert response.status == 200, path
        page = response.read().decode()
    assert not re.search(r'(Fatal error|Parse error|Uncaught [A-Za-z]+)', page), path + ': PHP failure'
    return page
def request_redirect(opener, path, fields=None):
    target = path if path.startswith('http://') or path.startswith('https://') else base + path
    data = None if fields is None else urlencode(fields, doseq=True).encode()
    try:
        with opener.open(target, data=data, timeout=20) as response:
            code, location, page = response.status, response.headers.get('Location'), response.read().decode()
    except HTTPError as error:
        code, location, page = error.code, error.headers.get('Location'), error.read().decode()
    assert not re.search(r'(Fatal error|Parse error|Uncaught [A-Za-z]+)', page), path + ': PHP failure'
    return code, location, page
with (repo / '.ci/http.log').open('w') as log:
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8080', '-t', str(root)], stdout=log, stderr=log)
    try:
        cookies = CookieJar()
        anonymous = build_opener(HTTPCookieProcessor(cookies))
        no_redirect = build_opener(HTTPCookieProcessor(cookies), NoRedirect())
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
            f'/custom/training/commercial.php?id={fixture["session"]}&enrollment_id={fixture["enrollment"]}': 'Native Buyer æøå',
            '/custom/training/myattendance.php': 'NATIVE-CI',
            '/custom/training/overview.php?search=NATIVE-CI': 'NATIVE-CI',
            f'/custom/training/reservations.php?id={fixture["session"]}': 'Participant',
            f'/product/card.php?id={fixture["product"]}': f'/custom/training/course.php?id={fixture["product"]}',
        }
        for path, expected in paths.items():
            page = request(anonymous, path)
            assert 'name="password"' not in page and expected in unescape(page), f'{path}: expected rendered content missing'
            print('OK: authenticated native HTTP page', path)
        commercial_path = f'/custom/training/commercial.php?id={fixture["session"]}&enrollment_id={fixture["enrollment"]}'
        page = request(anonymous, commercial_path)
        parser = Inputs(); parser.feed(page)
        fields = dict(token=parser.values['token'], id=fixture['session'], enrollment_id=fixture['enrollment'],
                      action='roles', revision='1', buyer=str(fixture['company']), payer='', employer='', reason='Native HTTP role correction')
        page = request(anonymous, commercial_path, fields)
        assert 'Commercial roles saved.' in unescape(page), 'Commercial role form failed'
        assert 'Native HTTP role correction' in unescape(page), 'Commercial history reason missing'
        page = request(anonymous, commercial_path, dict(fields, payer=str(fixture['company']), reason='Stale role form'))
        assert 'Another user changed these roles.' in unescape(page), 'Stale commercial form was not rejected'
        forged = dict(fields, token='invalid-token', revision='2', buyer='', reason='Forged role form')
        page = request(anonymous, commercial_path, forged)
        assert 'Forged role form' not in unescape(page), 'Invalid CSRF token mutated role history'
        print('OK: native commercial form, history, revision conflict and CSRF')
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
        without_token = dict(approval); without_token.pop('token')
        try:
            request(anonymous, path, without_token)
            raise AssertionError('Reservation write without CSRF token was accepted')
        except HTTPError as error:
            assert error.code == 403, 'Missing CSRF token must be refused by core'
        forged = dict(approval, token='invalid-token', action='releaseReservation')
        page = request(anonymous, path, forged)
        assert '<td>Active reservation</td>' in unescape(page), 'Forged token changed reservation status'
        print('OK: native missing and invalid CSRF token cannot mutate reservation')
        page = request(anonymous, path, approval)
        assert '<td>Confirmed</td>' in unescape(page), 'Reservation approval form failed'
        page = request(anonymous, f'/custom/training/session.php?id={fixture["reservation_session"]}')
        assert 'Confirmed: 2' in unescape(page) and 'Reserved: 0' in unescape(page), 'Native HTTP conversion must replace reservation with two confirmed seats'
        print('OK: native reservation form, duplicate submit and whole-group approval')

        # A9: exercise the actual checkout form over HTTP, then complete that
        # same checkout against the native invoice/payment records in the
        # disposable official Dolibarr installation extracted from the ZIP.
        checkout_path = f'/custom/training/checkout.php?session_id={fixture["checkout_session"]}'
        page = request(anonymous, checkout_path)
        parser = Inputs(); parser.feed(page)
        assert parser.values.get('token'), 'HTTP checkout form CSRF token missing'
        assert 'participantForm' in page, 'HTTP checkout form did not render'
        participant_fields = {
            'token': parser.values['token'],
            'session_id': str(fixture['checkout_session']),
            'action': 'submitParticipants',
            'participants[0][first_name]': 'HTTP',
            'participants[0][last_name]': 'Checkout',
            'participants[0][email]': fixture['checkout_email'],
            'participants[0][phone]': ''
        }
        code, location, body = request_redirect(no_redirect, '/custom/training/checkout.php', participant_fields)
        response_excerpt = unescape(body.split('</head>', 1)[-1])[:1800]
        assert code in (302, 303) and location, f'HTTP reservation did not redirect to payment step: status={code}; response={response_excerpt}'
        payment_step = urljoin(base + '/', location)
        parsed_step = urlparse(payment_step)
        step_query = parse_qs(parsed_step.query)
        assert parsed_step.path.endswith('/custom/training/checkout.php') and step_query.get('action') == ['createPayment'], f'Unexpected checkout continuation: {location}'
        assert step_query.get('hold_id') and step_query.get('session_id') == [str(fixture['checkout_session'])], 'SeatHold identity missing from checkout continuation'

        code, payment_location, body = request_redirect(no_redirect, payment_step)
        assert code in (302, 303) and payment_location, f'Checkout did not redirect to native Dolibarr payment: status={code}'
        payment_url = urljoin(base + '/', payment_location)
        parsed_payment = urlparse(payment_url)
        payment_query = parse_qs(parsed_payment.query)
        assert '/payment/' in parsed_payment.path.lower(), f'Checkout did not redirect into Dolibarr payment: {payment_location}'
        assert payment_query.get('e') and payment_query['e'][0].isdigit(), 'Native payment redirect lost entity context'
        assert payment_query.get('ref') and payment_query['ref'][0], f'Native payment redirect has no invoice reference: {payment_location}'
        print('OK: HTTP checkout creates a SeatHold and redirects to its native Dolibarr invoice payment')

        inspect = subprocess.run(
            ['php', str(repo / 'tests/native_checkout_finalize.php'), str(fixture['checkout_session']), 'inspect'],
            cwd=repo, capture_output=True, text=True, timeout=60
        )
        if inspect.returncode:
            raise RuntimeError('Could not inspect HTTP-created checkout: ' + inspect.stdout + inspect.stderr)
        checkout_before_payment = json.loads(inspect.stdout.strip().splitlines()[-1])
        success_path = f'/custom/training/success.php?session_id={fixture["checkout_session"]}&checkout_session_id={checkout_before_payment["checkout_session_id"]}'
        pending_page = request(anonymous, success_path)
        assert 'Payment confirmation pending' in unescape(pending_page), 'Unpaid checkout must not display a success confirmation'
        assert 'TrainingPaymentReceived' not in unescape(pending_page), 'Unpaid checkout falsely claimed payment was received'

        finalize = subprocess.run(
            ['php', str(repo / 'tests/native_checkout_finalize.php'), str(fixture['checkout_session'])],
            cwd=repo, capture_output=True, text=True, timeout=60
        )
        if finalize.returncode:
            raise RuntimeError('HTTP checkout native payment finalization failed: ' + finalize.stdout + finalize.stderr)
        result_file = repo / '.ci/native-http-checkout-result.json'
        assert result_file.is_file(), 'Native HTTP checkout finalization result missing'
        checkout_result = json.loads(result_file.read_text())
        success_path = f'/custom/training/success.php?session_id={fixture["checkout_session"]}&checkout_session_id={checkout_result["checkout_session_id"]}'
        success_page = request(anonymous, success_path)
        assert 'TrainingPaymentReceived' not in unescape(success_page), 'Paid HTTP checkout confirmation rendered an unresolved translation key'
        assert 'HTTP' in unescape(success_page) and 'Checkout' in unescape(success_page), 'Paid HTTP checkout confirmation did not show the participant'
        assert 'Payment confirmation pending' not in unescape(success_page), 'Completed checkout still displays pending confirmation'
        failure_page = request(anonymous, f'/custom/training/failure.php?session_id={fixture["checkout_session"]}&error=payment_declined')
        assert 'payment_declined' in unescape(failure_page), 'Payment failure endpoint did not render the supplied safe error code'
        payment_admin_page = request(anonymous, f'/custom/training/payment.php?id={fixture["checkout_session"]}')
        assert 'HTTP-CHECKOUT-CI' in unescape(payment_admin_page), 'Paid HTTP checkout session missing from native payment page'
        print('OK: same HTTP-created checkout refuses false success, observes native Paiement and confirms enrollment')

        outsider = build_opener(HTTPCookieProcessor(CookieJar()))
        page = request(outsider, f'/custom/training/attendance.php?id={fixture["session"]}&slot_id={fixture["slot"]}')
        assert 'name="password"' in page and 'Participant' not in page, 'Anonymous access must require native login'
        print('OK: anonymous attendance access requires native login')
    finally:
        server.terminate()
        server.wait(timeout=10)
