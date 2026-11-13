<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Segnala un problema": la segnalazione (con screenshot della pagina) diventa una richiesta
 * nel task manager, come da ERP ed emmycasa. Il titolo porta [Orio] per riconoscerla.
 */
class SupportRequestController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'page_url' => 'nullable|string|max:500',
            'image' => 'nullable|image|max:5120',
            'extra_image' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,zip,doc,docx,xls,xlsx,csv,txt',
        ]);

        $user = $request->user();

        try {
            $http = Http::timeout(15)->asMultipart()
                ->attach('title', '[Orio] ' . $data['title'])
                ->attach('description', $data['description'])
                ->attach('user_name', $user->name)
                ->attach('page_url', $data['page_url'] ?? '')
                ->attach('api_key', (string) config('services.task_manager.api_key'))
                ->attach('assigned_to', (string) config('services.task_manager.default_developer_id'));

            if ($user->email) {
                $http = $http->attach('user_email', $user->email);
            }
            foreach (['image', 'extra_image'] as $field) {
                if ($file = $request->file($field)) {
                    $http = $http->attach($field, fopen($file->getRealPath(), 'r'), $file->getClientOriginalName());
                }
            }

            $response = $http->post(rtrim(config('services.task_manager.url'), '/') . '/api/erp/create-request');

            if ($response->successful() && $response->json('success')) {
                return response()->json(['success' => true, 'message' => 'Segnalazione inviata: ti ricontattiamo al più presto.']);
            }

            Log::error('Segnalazione: errore dal task manager', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
        } catch (\Throwable $e) {
            Log::error('Segnalazione: task manager non raggiungibile', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => false, 'message' => 'Invio non riuscito, riprova tra poco.'], 502);
    }
}
