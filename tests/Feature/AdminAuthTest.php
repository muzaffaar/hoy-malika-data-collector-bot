<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
class AdminAuthTest extends TestCase {
 use RefreshDatabase;
 public function test_dashboard_requires_authentication(): void { $this->get('/admin/dashboard')->assertRedirect('/admin/login'); }
 public function test_admin_can_login_and_logout(): void {
  $u=User::factory()->create(['email'=>'admin@example.com','password'=>Hash::make('secret-password')]);
  $this->post('/admin/login',['email'=>$u->email,'password'=>'secret-password'])->assertRedirect('/admin/dashboard');
  $this->assertAuthenticatedAs($u);
  $this->post('/admin/logout')->assertRedirect('/admin/login'); $this->assertGuest();
 }
}
