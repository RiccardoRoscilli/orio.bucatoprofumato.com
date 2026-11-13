<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_is_forwarded_to_task_manager_with_screenshot(): void
    {
        Http::fake(['taskman.modulioscommerce.com/*' => Http::response(['success' => true])]);
        $user = User::create(['name' => 'Pamela', 'username' => 'pam', 'password' => 'x', 'active' => true]);

        $this->actingAs($user)->post('/assistenza', [
            'title' => 'Lo scontrino non parte',
            'description' => 'Premo emetti e resta fermo',
            'page_url' => 'https://orio.bucatoprofumato.com/',
            'image' => UploadedFile::fake()->image('screenshot.png'),
        ])->assertOk()->assertJson(['success' => true]);

        Http::assertSent(function (Request $r) {
            $fields = collect($r->data())->pluck('contents', 'name');

            return $r->url() === 'https://taskman.modulioscommerce.com/api/erp/create-request'
                && $fields['title'] === '[Orio] Lo scontrino non parte'
                && $fields['user_name'] === 'Pamela'
                && $fields->has('image');
        });
    }

    public function test_task_manager_error_is_reported(): void
    {
        Http::fake(['*' => Http::response(['success' => false], 500)]);
        $user = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);

        $this->actingAs($user)->postJson('/assistenza', ['title' => 'x', 'description' => 'y'])
            ->assertStatus(502)->assertJson(['success' => false]);
    }
}
