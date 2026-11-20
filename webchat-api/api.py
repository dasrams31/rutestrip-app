import os
import sys
import glob
import re
import math
import json
import uuid
import subprocess
from typing import Optional
from fastapi import FastAPI, Query, HTTPException, Header, Body, Request
from fastapi.responses import FileResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.append(BASE_DIR)
# Shared SBERT scoring + Laravel processor modules live in ../python
PYTHON_DIR = os.path.join(os.path.dirname(BASE_DIR), 'python')
if os.path.isdir(PYTHON_DIR) and PYTHON_DIR not in sys.path:
    sys.path.append(PYTHON_DIR)
from rekomendasi_pendakian import RecommendationSystem
import satellite_map
import gpx_exporter
import itinerary_logistics
import survival_budget
import porter_transport
import cek_cuaca_gunung
import info_rutestrip
import subscriber_report
import broadcast_channel_bulletin
import broadcast_weekend_getaway
import broadcast_survival_tips
import broadcast_bot_usage
import sso_auth
import guardrail
import chat_history_handler

app = FastAPI(
    title='RuteStrip Pendakian API',
    description='REST API Asisten Pendakian Gunung & Multi-Session Chat History untuk WebChat AI (akun via SSO rutestrip.ai.id)',
    version='1.8.0'
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=['*'],
    allow_credentials=True,
    allow_methods=['*'],
    allow_headers=['*'],
)

@app.middleware("http")
async def security_headers_middleware(request, call_next):
    response = await call_next(request)
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["X-Frame-Options"] = "DENY"
    response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    response.headers["Strict-Transport-Security"] = "max-age=63072000; includeSubDomains; preload"
    return response

rec_system = RecommendationSystem()
gpx_dir = os.path.join(BASE_DIR, 'gpx_db') if os.path.exists(os.path.join(BASE_DIR, 'gpx_db')) else os.path.join(os.path.dirname(os.path.abspath(__file__)), "gpx_db")
rec_system.index_directory(gpx_dir, use_cache=True)


def _mount_laravel_processor():
    """Serve the Laravel SBERT processor (python/api.py) from this process at
    /processor, reusing the already loaded model so the VPS keeps a single copy."""
    import importlib.util
    path = os.path.join(PYTHON_DIR, 'api.py')
    if not os.path.exists(path):
        return
    spec = importlib.util.spec_from_file_location('rutestrip_processor_api', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    rec_system._load_model()
    module.use_shared_model(rec_system.model)
    app.mount('/processor', module.app)


_mount_laravel_processor()

# Schema Models
class ChatPromptModel(BaseModel):
    prompt: str
    session_id: Optional[str] = None
    locale: Optional[str] = None   # 'id' (default) or 'en'
    # The web chat generates the answer itself (Gemini) and sends it here once
    # it is complete, so the saved history holds the real answer.
    bot_reply: Optional[str] = None
    reply_type: Optional[str] = None

MAX_SAVED_REPLY_CHARS = 20000

@app.get('/')
def root():
    return {
        'status': 'online',
        'service': 'RuteStrip Pendakian Bot REST API & WebChat history',
        'version': '1.8.0',
        'docs_url': '/docs'
    }

# --- RETIRED AUTH ENDPOINTS ---
# Accounts moved to Laravel (rutestrip.ai.id) with single sign-on, see docs/SSO.md.
# The old users/auth_tokens tables are kept in PostgreSQL but no longer used.

RETIRED_AUTH_MESSAGE = (
    'Endpoint ini sudah tidak dipakai. Akun RuteStrip sekarang dikelola di https://rutestrip.ai.id '
    '(masuk/daftar lewat https://chat.rutestrip.ai.id atau https://rutestrip.ai.id). '
    'This endpoint was retired; RuteStrip accounts are managed at https://rutestrip.ai.id.'
)

@app.api_route('/api/auth/register', methods=['GET', 'POST'], include_in_schema=False)
@app.api_route('/api/auth/login', methods=['GET', 'POST'], include_in_schema=False)
@app.api_route('/api/auth/reset-password', methods=['GET', 'POST'], include_in_schema=False)
@app.api_route('/api/auth/me', methods=['GET', 'POST'], include_in_schema=False)
@app.api_route('/api/auth/altcha-challenge', methods=['GET'], include_in_schema=False)
def retired_auth_endpoint():
    return JSONResponse({'status': 'gone', 'detail': RETIRED_AUTH_MESSAGE, 'error': RETIRED_AUTH_MESSAGE}, status_code=410)


def _require_user(authorization: Optional[str]) -> dict:
    """SSO identity for the request (Bearer rs_sso token), else HTTP 401."""
    if not authorization:
        raise HTTPException(status_code=401, detail='Authorization header wajib disertakan.')
    identity = sso_auth.authenticate(authorization)
    if not identity:
        raise HTTPException(status_code=401, detail='Sesi tidak valid atau sudah berakhir. Silakan masuk lagi.')
    return identity

# --- AI CHAT QUERY & MULTI-SESSION CHAT HISTORY ENDPOINTS ---

@app.post('/api/chat/query', summary='Filter & Process WebChat AI Prompt via Guardrail + Session Grouping')
def process_chat_query(body: ChatPromptModel, authorization: Optional[str] = Header(None)):
    user_id = "anonymous_guest"
    if authorization:
        identity = sso_auth.authenticate(authorization)
        if identity:
            user_id = identity['key']
    english = (body.locale or '').lower() == 'en'

    session_id = body.session_id or str(uuid.uuid4())

    chat_history_handler.save_session_message(user_id=user_id, session_id=session_id, sender='user', text=body.prompt)

    if body.bot_reply is not None:
        chat_history_handler.save_session_message(
            user_id=user_id,
            session_id=session_id,
            sender='bot',
            text=body.bot_reply[:MAX_SAVED_REPLY_CHARS],
            meta={'type': (body.reply_type or 'llm')[:30]},
        )
        return {'status': 'success', 'session_id': session_id}

    g_res = guardrail.validate_webchat_query(body.prompt)
    if not g_res.get('allowed'):
        bot_reply = g_res.get('message')
        chat_history_handler.save_session_message(user_id=user_id, session_id=session_id, sender='bot', text=bot_reply, meta={'guardrail_passed': False})
        return {
            'status': 'rejected',
            'guardrail_passed': False,
            'session_id': session_id,
            'response': bot_reply
        }

    query = g_res.get('clean_prompt')
    rec_results = rec_system.search(query, top_n=3)

    formatted_results = []
    for r in rec_results:
        item = r['item']
        formatted_results.append({
            'mountain_route': item['name'],
            'stats': item['stats'],
            'narrative': item['narrative']
        })

    bot_reply = (f"Here is the best hiking information for '{query}':" if english
                 else f"Berikut adalah informasi pendakian terbaik berdasarkan kriteria '{query}':")
    chat_history_handler.save_session_message(
        user_id=user_id,
        session_id=session_id,
        sender='bot',
        text=bot_reply,
        meta={'guardrail_passed': True, 'recommendations_count': len(formatted_results)}
    )

    return {
        'status': 'success',
        'guardrail_passed': True,
        'session_id': session_id,
        'query': query,
        'recommendations': formatted_results,
        'response': bot_reply
    }

@app.get('/api/chat/sessions', summary='Ambil Daftar Sesi Percakapan Pengguna')
def get_user_chat_sessions(authorization: Optional[str] = Header(None)):
    user_id = _require_user(authorization)['key']
    sessions = chat_history_handler.get_user_sessions(user_id=user_id)
    return {
        'status': 'success',
        'user_id': user_id,
        'total_sessions': len(sessions),
        'sessions': sessions
    }

@app.get('/api/chat/sessions/{session_id}', summary='Ambil Pesan Obrolan Spesifik dari Satu Sesi')
def get_session_chat_messages(session_id: str, authorization: Optional[str] = Header(None)):
    user_id = _require_user(authorization)['key']
    messages = chat_history_handler.get_session_messages(user_id=user_id, session_id=session_id)
    return {
        'status': 'success',
        'user_id': user_id,
        'session_id': session_id,
        'total_messages': len(messages),
        'messages': messages
    }

@app.delete('/api/chat/sessions/{session_id}', summary='Hapus Satu Sesi Percakapan Spesifik')
def delete_single_session(session_id: str, authorization: Optional[str] = Header(None)):
    user_id = _require_user(authorization)['key']
    chat_history_handler.delete_session(user_id=user_id, session_id=session_id)
    return {
        'status': 'success',
        'message': f'Sesi percakapan {session_id} berhasil dihapus.'
    }

@app.delete('/api/chat/history', summary='Hapus Seluruh Sesi Riwayat Obrolan Pengguna')
def delete_all_chat_history(authorization: Optional[str] = Header(None)):
    user_id = _require_user(authorization)['key']
    chat_history_handler.clear_all_user_sessions(user_id=user_id)
    return {
        'status': 'success',
        'message': 'Seluruh riwayat percakapan berhasil dibersihkan.'
    }

# --- ENDPOINTS INFORMASI & BOT ---

@app.get('/api/info', summary='Informasi Resmi Komunitas & Platform RuteStrip')
def get_info():
    info_path = os.path.join(BASE_DIR, 'info_rutestrip.json')
    if os.path.exists(info_path):
        with open(info_path, 'r') as f:
            data = json.load(f)
        return {'status': 'success', 'data': data}
    return {'status': 'success', 'data': {
        'website': 'https://rutestrip.web.id',
        'ai_chat': 'https://airutestrip.web.id',
        'telegram_group': '@rutestrip_group',
        'telegram_channel': '@rutestrip'
    }}

@app.get('/api/news/bulletin', summary='Daily Mountain Bulletin & News Realtime')
def get_bulletin():
    bulletin_text = broadcast_channel_bulletin.generate_bulletin()
    return {'status': 'success', 'bulletin': bulletin_text}

@app.get('/api/getaway', summary='Rekomendasi Rute Harian & Weekend Getaway')
def get_daily_getaway():
    item = broadcast_weekend_getaway.get_next_recommendation()
    return {'status': 'success', 'recommendation': item}

@app.get('/api/survival/tips', summary='Edukasi Survival & Etika Pendaki Harian')
def get_survival_tips():
    item = broadcast_survival_tips.get_next_tips()
    return {'status': 'success', 'tips': item}

@app.get('/api/stats/subscribers', summary='Statistik & Laporan Pengguna Bot')
def get_subscriber_stats():
    report_text = subscriber_report.generate_report()
    subscribers_path = os.path.join(BASE_DIR, 'subscribers.json')
    sub_data = {}
    if os.path.exists(subscribers_path):
        with open(subscribers_path, 'r') as f:
            sub_data = json.load(f)
    return {
        'status': 'success',
        'total_subscribers': len(sub_data),
        'report_summary': report_text
    }

@app.get('/api/rekomendasi', summary='Rekomendasi Rute AI (SBERT)')
def get_recommendation(query: str = Query(..., description='Kata kunci kriteria pendakian'), limit: int = 5):
    results = rec_system.search(query, top_n=limit)
    formatted = []
    for r in results:
        item = r['item']
        formatted.append({
            'mountain_route': item['name'],
            'similarity_score': round(r['similarity'], 4),
            'stats': item['stats'],
            'narrative': item['narrative']
        })
    return {'query': query, 'total': len(formatted), 'results': formatted}

_WEATHER_CACHE = {'at': 0.0, 'text': None}
_WEATHER_TTL_SECONDS = 15 * 60


def _cached_all_weather() -> str:
    """Open-Meteo is queried for every mountain sequentially (~10+ s), so keep
    the summary for 15 minutes; forecasts don't change faster than that."""
    import time
    now = time.time()
    if _WEATHER_CACHE['text'] is None or now - _WEATHER_CACHE['at'] > _WEATHER_TTL_SECONDS:
        _WEATHER_CACHE['text'] = cek_cuaca_gunung.check_all_weather()
        _WEATHER_CACHE['at'] = now
    return _WEATHER_CACHE['text']


@app.get('/api/cuaca', summary='Prakiraan Cuaca Live Realtime (Open-Meteo)')
def get_weather(mountain: Optional[str] = Query(None, description='Nama gunung')):
    weather_text = _cached_all_weather()
    if mountain:
        lines = [line for line in weather_text.splitlines() if mountain.lower() in line.lower()]
        return {'mountain': mountain, 'weather_info': lines if lines else weather_text}
    return {'weather_info': weather_text}

def get_safe_mountain_name(mtn: str) -> str:
    clean = re.sub(r'[^a-zA-Z0-9]', '', (mtn or '').lower().strip())
    noise_words = ['topografinya', 'satelitnya', 'petanya', 'peta', 'topo', 'topografi', 'satelit', 'foto', 'gambar']
    if not clean or clean in noise_words or len(clean) < 3:
        return 'merbabu'
    gpx = gpx_exporter.find_gpx(clean)
    if not gpx or not os.path.exists(gpx):
        return 'merbabu'
    return clean

@app.get('/api/map/satellite', summary='Peta Satelit / Topografi Kontur (PNG)')
def get_satellite_map(mountain: str = Query('merbabu', description='Nama gunung'), map_type: str = Query('satelit', description='satelit atau topografi')):
    safe_mtn = get_safe_mountain_name(mountain)
    clean_type = map_type.lower().strip()
    output_path = f'/tmp/api_map_{safe_mtn}_{clean_type}.png'
    
    if os.path.exists(output_path) and os.path.getsize(output_path) > 1000:
        return FileResponse(output_path, media_type='image/png', filename=f'map_{safe_mtn}.png')
    
    q_str = f'{safe_mtn} topo' if clean_type in ['topografi', 'topo'] else safe_mtn
    try:
        res_path = satellite_map.generate_satellite_map(query=q_str, output_img=output_path)
        return FileResponse(res_path, media_type='image/png', filename=f'map_{safe_mtn}.png')
    except Exception as e:
        fallback_path = f'/tmp/api_map_merbabu_{clean_type}.png'
        try:
            res_path = satellite_map.generate_satellite_map(query=('merbabu topo' if clean_type in ['topografi', 'topo'] else 'merbabu'), output_img=fallback_path)
            return FileResponse(res_path, media_type='image/png', filename='map_merbabu.png')
        except Exception:
            return JSONResponse({'status': 'fallback', 'message': 'Map rendered default'}, status_code=200)

@app.get('/api/gpx', summary='Export File GPX / KML Track Offline')
def get_gpx_file(mountain: str = Query('merbabu', description='Nama gunung'), format: str = Query('gpx', description='gpx atau kml')):
    safe_mtn = get_safe_mountain_name(mountain)
    filepath = gpx_exporter.find_gpx(safe_mtn) or gpx_exporter.find_gpx('merbabu')
    if not filepath or not os.path.exists(filepath):
        raise HTTPException(status_code=404, detail=f'File GPX tidak ditemukan.')
    
    if format.lower() == 'kml':
        kml_path = gpx_exporter.gpx_to_kml(filepath)
        return FileResponse(kml_path, media_type='application/vnd.google-earth.kml+xml', filename=f'{safe_mtn}.kml')
    
    return FileResponse(filepath, media_type='application/gpx+xml', filename=os.path.basename(filepath))

@app.get('/api/itinerary', summary='Naismith Itinerary Generator')
def get_itinerary(mountain: str = Query('merbabu'), mode: str = Query('2d1n')):
    safe_mtn = get_safe_mountain_name(mountain)
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'itinerary_logistics.py'), 'itinerary', safe_mtn, mode], capture_output=True, text=True)
    return {'mountain': safe_mtn, 'mode': mode, 'itinerary': res.stdout.strip()}

@app.get('/api/logistik', summary='Kalkulator Perbekalan Air & Makanan')
def get_logistics(people: int = Query(3), days: int = Query(2)):
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'itinerary_logistics.py'), 'logistics', str(people), str(days)], capture_output=True, text=True)
    return {'people': people, 'days': days, 'logistics': res.stdout.strip()}

@app.get('/api/biaya', summary='Kalkulator Biaya Pendakian')
def get_budget(mountain: str = Query('sumbing'), people: int = Query(3), days: int = Query(2)):
    safe_mtn = get_safe_mountain_name(mountain)
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'survival_budget.py'), 'budget', safe_mtn, str(people), str(days)], capture_output=True, text=True)
    return {'mountain': safe_mtn, 'people': people, 'days': days, 'budget_info': res.stdout.strip()}

@app.get('/api/survival', summary='Panduan Survival First Aid')
def get_survival(topic: str = Query('hipotermia')):
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'survival_budget.py'), 'survival', topic], capture_output=True, text=True)
    return {'topic': topic, 'guide': res.stdout.strip()}

@app.get('/api/porter', summary='Kontak Porter & Basecamp')
def get_porter(mountain: str = Query('sumbing')):
    safe_mtn = get_safe_mountain_name(mountain)
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'porter_transport.py'), safe_mtn], capture_output=True, text=True)
    return {'mountain': safe_mtn, 'porter_info': res.stdout.strip()}

@app.get('/api/patungan', summary='Kalkulator Patungan & Split Bill Tim')
def get_split_bill(
    mountain: str = Query('sumbing', description='Nama gunung atau default'),
    people: int = Query(4, description='Jumlah pendaki'),
    days: int = Query(2, description='Jumlah hari'),
    carter: Optional[int] = Query(None, description='Biaya carter/transport bersama'),
    porter: Optional[int] = Query(None, description='Biaya porter bersama'),
    tenda: Optional[int] = Query(None, description='Biaya sewa tenda bersama')
):
    args = [mountain, str(people), str(days)]
    if carter:
        args.append(f'carter={carter}')
    if porter:
        args.append(f'porter={porter}')
    if tenda:
        args.append(f'tenda={tenda}')
    
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'split_bill.py')] + args, capture_output=True, text=True)
    return {'mountain': mountain, 'people': people, 'days': days, 'split_bill_info': res.stdout.strip()}

@app.get('/api/guidebook/pdf', summary='Download E-Guidebook PDF Resmi')
def get_guidebook_pdf(mountain: str = Query('sumbing', description='Nama gunung')):
    safe_mtn = get_safe_mountain_name(mountain)
    pdf_path = f'/tmp/guidebook_{safe_mtn}.pdf'
    
    # Generate or return cached
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'guidebook_pdf.py'), safe_mtn], capture_output=True, text=True)
    out = res.stdout.strip()
    if out.startswith('GUIDEBOOK:'):
        actual_path = out.split(':', 1)[1].strip()
        if os.path.exists(actual_path):
            return FileResponse(actual_path, media_type='application/pdf', filename=f'guidebook_{safe_mtn}.pdf')
    
    if os.path.exists(pdf_path):
        return FileResponse(pdf_path, media_type='application/pdf', filename=f'guidebook_{safe_mtn}.pdf')
    raise HTTPException(status_code=404, detail='Gagal membuat file Guidebook PDF.')

@app.get('/api/story/card', summary='Download 9:16 Story Infografis Rute (PNG)')
def get_story_card(mountain: str = Query('sumbing', description='Nama gunung')):
    safe_mtn = get_safe_mountain_name(mountain)
    out_path = f'/tmp/story_{safe_mtn}.png'
    res = subprocess.run([sys.executable, os.path.join(BASE_DIR, 'story_infografis.py'), safe_mtn], capture_output=True, text=True)
    out = res.stdout.strip()
    if out.startswith('STORY:'):
        actual_path = out.split(':', 1)[1].strip()
        if os.path.exists(actual_path):
            return FileResponse(actual_path, media_type='image/png', filename=f'story_{safe_mtn}.png')
    if os.path.exists(out_path):
        return FileResponse(out_path, media_type='image/png', filename=f'story_{safe_mtn}.png')
    raise HTTPException(status_code=404, detail='Gagal membuat Story Card Infografis.')
