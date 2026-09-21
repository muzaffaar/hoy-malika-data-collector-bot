<?php

namespace Tests\Feature;

use App\Services\Backup\GoogleDriveDestination;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriveFolderCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['dataset.drive_folder' => 'root-folder', 'dataset.drive_client_id' => 'id', 'dataset.drive_client_secret' => 'secret', 'dataset.drive_refresh_token' => 'refresh']);
    }

    private function fakeFolder($folderResponse): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token']), 'www.googleapis.com/drive/v3/files/root-folder*' => $folderResponse]);
    }

    public function test_a_writable_folder_passes(): void
    {
        $this->fakeFolder(Http::response(['id' => 'root-folder', 'name' => 'Dataset', 'mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => true]]));

        $this->assertNull(app(GoogleDriveDestination::class)->folderError());
    }

    public function test_folder_not_found_explains_the_likely_causes(): void
    {
        $this->fakeFolder(Http::response(['error' => ['message' => 'File not found: root-folder.', 'errors' => [['reason' => 'notFound']]]], 404));

        $error = app(GoogleDriveDestination::class)->folderError();

        $this->assertStringContainsString('notFound', $error);
        $this->assertStringContainsString('auth/drive', $error);
    }

    public function test_read_only_access_is_reported(): void
    {
        $this->fakeFolder(Http::response(['id' => 'root-folder', 'name' => 'Dataset', 'mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => false]]));

        $this->assertStringContainsString('cannot add files', app(GoogleDriveDestination::class)->folderError());
    }

    public function test_disabled_drive_api_is_reported(): void
    {
        $this->fakeFolder(Http::response(['error' => ['message' => 'Google Drive API has not been used in project 1 before or it is disabled.', 'errors' => [['reason' => 'accessNotConfigured']]]], 403));

        $this->assertStringContainsString('enable the Google Drive API', app(GoogleDriveDestination::class)->folderError());
    }
}
