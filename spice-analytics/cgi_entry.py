"""One-request ASGI/CGI gateway for shared hosting, without a resident server.

The web server must support executable .cgi files and Python >= 3.11.
Only the generated launcher and static/ belong in the document root.
"""
from __future__ import annotations
import asyncio
import io
import os
import sys
from urllib.parse import unquote, urlsplit

MAX_BODY = 4 * 1024 * 1024


async def dispatch(application, environ, body):
    headers = []
    for key, value in environ.items():
        if key.startswith('HTTP_'):
            headers.append((key[5:].replace('_', '-').lower().encode('ascii'), value.encode('latin-1')))
    for key in ('CONTENT_TYPE', 'CONTENT_LENGTH'):
        if environ.get(key):
            headers.append((key.replace('_', '-').lower().encode(), environ[key].encode('latin-1')))
    original = urlsplit(environ.get('REQUEST_URI', '/'))
    raw_path = original.path.encode('ascii')
    scheme = 'https' if environ.get('HTTPS', '').lower() in ('on', '1') else environ.get('REQUEST_SCHEME', 'http')
    scope = {'type':'http', 'asgi':{'version':'3.0','spec_version':'2.3'}, 'http_version':'1.1',
             'method':environ.get('REQUEST_METHOD', 'GET'), 'scheme':scheme,
             'path':unquote(original.path), 'raw_path':raw_path, 'root_path':'',
             'query_string':environ.get('QUERY_STRING', original.query).encode('ascii'),
             'headers':headers, 'client':(environ.get('REMOTE_ADDR', 'unknown'), 0),
             'server':(environ.get('SERVER_NAME', 'localhost'), int(environ.get('SERVER_PORT', 443 if scheme=='https' else 80)))}
    result = {'status':500, 'headers':[], 'body':io.BytesIO()}
    sent = False
    finished = asyncio.Event()

    async def receive():
        nonlocal sent
        if not sent:
            sent = True
            return {'type':'http.request', 'body':body, 'more_body':False}
        await finished.wait()
        return {'type':'http.disconnect'}

    async def send(message):
        if message['type'] == 'http.response.start':
            result['status'] = message['status']
            result['headers'] = message['headers']
        elif message['type'] == 'http.response.body':
            result['body'].write(message.get('body', b''))
            if not message.get('more_body', False):
                finished.set()

    await application(scope, receive, send)
    if not finished.is_set():
        raise RuntimeError('Incomplete ASGI response')
    return result['status'], result['headers'], result['body'].getvalue()


def main():
    try:
        length = int(os.environ.get('CONTENT_LENGTH') or 0)
        if length < 0 or length > MAX_BODY:
            status, headers, body = 413, [], b'Request too large'
        else:
            body = sys.stdin.buffer.read(length)
            if len(body) != length:
                raise ValueError('Incomplete request')
            from app import app
            status, headers, body = asyncio.run(dispatch(app, dict(os.environ), body))
    except Exception:
        # No traceback or request data in a public response or hosting access log.
        status, headers, body = 503, [], 'Кабинет временно недоступен. Проверьте установку и закрытые настройки.'.encode()
    output = [b'Status: ' + str(status).encode()]
    output.extend(key + b': ' + value for key, value in headers
                  if key.lower() not in (b'transfer-encoding', b'connection', b'content-length'))
    output.extend([b'Content-Length: ' + str(len(body)).encode(), b'Cache-Control: no-store', b'X-Robots-Tag: noindex, nofollow'])
    sys.stdout.buffer.write(b'\r\n'.join(output) + b'\r\n\r\n' + body)


if __name__ == '__main__':
    main()
