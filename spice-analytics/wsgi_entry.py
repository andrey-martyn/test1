"""Optional WSGI entry point when the hosting panel provides a Python runner.

Set ANALYTICS_DATA_DIR and ANALYTICS_PUBLIC_URL BEFORE importing this module.
Native WSGI availability is a deployment prerequisite, never inferred from SSH.
"""
import asyncio
from http import HTTPStatus
from urllib.parse import quote

from app import app
from cgi_entry import MAX_BODY, dispatch


def application(environ, start_response):
    try:
        length = int(environ.get('CONTENT_LENGTH') or 0)
    except ValueError:
        length = -1
    if length < 0 or length > MAX_BODY:
        start_response('413 Request Entity Too Large', [('Content-Type','text/plain'),('Cache-Control','no-store')])
        return [b'Request too large']
    body = environ['wsgi.input'].read(length)
    request = {key:value for key,value in environ.items() if isinstance(value,str)}
    request['REQUEST_SCHEME'] = environ.get('wsgi.url_scheme','http')
    request.setdefault('REQUEST_URI', quote(environ.get('PATH_INFO','/')))
    status, headers, payload = asyncio.run(dispatch(app, request, body))
    start_response(f'{status} {HTTPStatus(status).phrase}', [(key.decode('latin-1'),value.decode('latin-1')) for key,value in headers])
    return [payload]
