<?php

namespace App\Http\Controllers;

use App\Models\CommunityMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Obrolan Pendaki": community chat between logged-in hikers, opened from the
 * left floating button (resources/views/components/floating-assistants.blade.php).
 *
 * JSON endpoints under /komunitas/chat (auth; guests get a JSON 401):
 *   GET    /komunitas/chat                 latest PAGE messages
 *   GET    /komunitas/chat?after=<id>      only newer messages (polled every ~4 s while open)
 *   GET    /komunitas/chat?before=<id>     PAGE older messages (scroll up)
 *   GET    /komunitas/chat/latest          {last_id} of other people's messages (unread badge)
 *   POST   /komunitas/chat                 {body} -> {message}
 *   DELETE /komunitas/chat/{message}       soft delete: own message, or any message for admins
 *   POST   /komunitas/chat/{message}/report flag a message for the admins
 *
 * Messages are stored as plain text; `html` in the JSON is the escaped text
 * with https:// links only (rel="noopener nofollow ugc").
 */
class CommunityChatController extends Controller
{
    public const ROOMS = ['umum'];
    public const MAX_LENGTH = 500;
    public const PAGE = 30;
    public const AFTER_LIMIT = 100;
    public const BURST = 6;
    public const BURST_SECONDS = 30;
    public const PER_DAY = 200;
    public const RETENTION_DAYS = 30;
    /** Polls also return messages deleted this recently, so open panels hide them. */
    private const DELETED_WINDOW_SECONDS = 90;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room'   => ['sometimes', 'string', Rule::in(self::ROOMS)],
            'after'  => ['sometimes', 'integer', 'min:0'],
            'before' => ['sometimes', 'integer', 'min:1'],
        ]);
        $room = $data['room'] ?? 'umum';
        $me   = $request->user();

        $query = CommunityMessage::withTrashed()
            ->with('user:id,name')
            ->where('room', $room)
            ->where('created_at', '>=', now()->subDays(self::RETENTION_DAYS));

        if (isset($data['after'])) {
            $messages = $query->where('id', '>', (int) $data['after'])->orderBy('id')->limit(self::AFTER_LIMIT)->get();
            $deleted  = CommunityMessage::onlyTrashed()
                ->where('room', $room)
                ->where('id', '<=', (int) $data['after'])
                ->where('deleted_at', '>=', now()->subSeconds(self::DELETED_WINDOW_SECONDS))
                ->pluck('id')->map(fn ($id) => (int) $id)->values();

            return response()->json([
                'room'     => $room,
                'messages' => $messages->map(fn ($m) => $this->present($m, $me))->values(),
                'has_more' => $messages->count() >= self::AFTER_LIMIT,
                'deleted'  => $deleted,
            ]);
        }

        if (isset($data['before'])) {
            $query->where('id', '<', (int) $data['before']);
        }
        $messages = $query->orderByDesc('id')->limit(self::PAGE + 1)->get();
        $hasMore  = $messages->count() > self::PAGE;

        return response()->json([
            'room'     => $room,
            'messages' => $messages->take(self::PAGE)->reverse()->map(fn ($m) => $this->present($m, $me))->values(),
            'has_more' => $hasMore,
        ]);
    }

    /** Cheap unread check for the closed button: newest message id written by someone else. */
    public function latest(Request $request): JsonResponse
    {
        $room = in_array($request->query('room'), self::ROOMS, true) ? $request->query('room') : 'umum';
        $last = CommunityMessage::where('room', $room)->where('user_id', '!=', $request->user()->id)->max('id');

        return response()->json(['last_id' => (int) $last]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $keys = [
            'community-chat:burst:' . $user->id => [self::BURST, self::BURST_SECONDS],
            'community-chat:day:' . $user->id   => [self::PER_DAY, 86400],
        ];

        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = RateLimiter::availableIn($key);

                return response()->json([
                    'error'       => 'throttled',
                    'message'     => str_contains($key, ':day:')
                        ? __('Batas pesan hari ini sudah tercapai. Lanjut ngobrol besok ya.')
                        : __('Pelan-pelan dulu. Tunggu :s detik lalu kirim lagi.', ['s' => $wait]),
                    'retry_after' => $wait,
                ], 429)->header('Retry-After', (string) $wait);
            }
        }

        $data = $request->validate([
            'body' => ['required', 'string'],
            'room' => ['sometimes', 'string', Rule::in(self::ROOMS)],
        ]);

        $body = self::clean($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('Pesan masih kosong.')]);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => __('Pesan terlalu panjang (maks. :max karakter).', ['max' => self::MAX_LENGTH])]);
        }

        foreach ($keys as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $message = CommunityMessage::create([
            'user_id' => $user->id,
            'room'    => $data['room'] ?? 'umum',
            'body'    => $body,
        ]);
        $message->setRelation('user', $user);

        return response()->json(['message' => $this->present($message, $user)], 201);
    }

    public function destroy(Request $request, CommunityMessage $message): JsonResponse
    {
        $user = $request->user();
        if ((int) $message->user_id !== (int) $user->id && ! $user->isAdmin()) {
            return response()->json(['error' => 'forbidden', 'message' => __('Kamu hanya bisa menghapus pesanmu sendiri.')], 403);
        }

        $message->delete();

        return response()->json(['message' => $this->present($message->load('user:id,name'), $user)]);
    }

    public function report(Request $request, CommunityMessage $message): JsonResponse
    {
        $user = $request->user();
        if ((int) $message->user_id === (int) $user->id) {
            return response()->json(['error' => 'own_message', 'message' => __('Pesanmu sendiri tidak bisa dilaporkan.')], 422);
        }

        if ($message->reported_at === null) {
            $message->forceFill(['reported_at' => now(), 'reported_by' => $user->id])->save();
        }

        return response()->json(['message' => $this->present($message->load('user:id,name'), $user), 'reported' => true]);
    }

    /**
     * "Daftar / Masuk" in the guest panel: remember the page the guest was on
     * (plus #obrolan so the panel opens again) and go to login or register.
     * Also served at the old /assistant/chat/login (route assistant.chat.login).
     */
    public function login(Request $request): RedirectResponse
    {
        $back = (string) $request->query('back', '/');
        // Local paths only (no open redirect): "/x", not "//host" or "/\host".
        if (! preg_match('#^/(?![/\\\\])#', $back) || preg_match('/[\x00-\x1F\x7F]/', $back)) {
            $back = '/';
        }
        $back = url(Str::before($back, '#')) . '#obrolan';

        if (Auth::check()) {
            return redirect($back);
        }

        $request->session()->put('url.intended', $back);

        return redirect()->route($request->query('to') === 'register' ? 'register' : 'login');
    }

    /**
     * Plain text as stored: no control characters (newlines and tabs stay), no
     * bidi overrides or zero-width spaces, no trailing spaces per line, at most
     * three blank lines in a row, trimmed.
     */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{Cc}\n\t]|[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text) ?? '';
        $text = preg_replace('/[\p{Zs}\t]+$/mu', '', $text) ?? '';
        $text = preg_replace('/\n{5,}/', "\n\n\n\n", $text) ?? '';

        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $text) ?? '';
    }

    /** Escaped text with https:// URLs turned into links. Newlines stay (rendered with white-space: pre-wrap). */
    public static function render(string $text): string
    {
        $parts = preg_split('~(https://[^\s<>"\'`]+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html  = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $html .= e($part);
                continue;
            }
            $tail = preg_match('~[.,;:!?)\]]+$~', $part, $m) ? $m[0] : '';
            $url  = $tail === '' ? $part : substr($part, 0, -strlen($tail));
            if (strlen($url) <= strlen('https://')) {
                $html .= e($part);
                continue;
            }
            $html .= '<a href="' . e($url) . '" target="_blank" rel="noopener nofollow ugc">' . e($url) . '</a>' . e($tail);
        }

        return $html;
    }

    private function present(CommunityMessage $m, User $me): array
    {
        $deleted = $m->trashed();
        $mine    = (int) $m->user_id === (int) $me->id;

        return [
            'id'         => (int) $m->id,
            'name'       => $m->user?->name ?? __('Pendaki'),
            'mine'       => $mine,
            'deleted'    => $deleted,
            'body'       => $deleted ? null : $m->body,
            'html'       => $deleted ? null : self::render($m->body),
            'created_at' => $m->created_at?->toIso8601String(),
            'can_delete' => ! $deleted && ($mine || $me->isAdmin()),
            'can_report' => ! $deleted && ! $mine,
            // Admins see every flag; others only their own report.
            'reported'   => $m->reported_at !== null && ($me->isAdmin() || (int) $m->reported_by === (int) $me->id),
        ];
    }
}
