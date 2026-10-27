<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\SalaryBillPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Uses an isolated local database only; never posts money to production. */
final class SalaryBulkStep06IsolationTest extends TestCase
{
    private function isolated(): void
    {
        $name=(string)config('database.connections.mysql.database');
        $host=strtolower((string)config('database.connections.mysql.host'));
        if (config('database.default')!=='mysql'
            || !preg_match('/^kau_.*_(test|staging)$/',$name)
            || !in_array($host,['127.0.0.1','localhost','::1'],true)
            || !in_array(app()->environment(),['local','testing'],true)) {
            $this->markTestSkipped('Requires isolated local kau_*_test/staging MySQL clone.');
        }
        if (!Schema::hasTable('salary_bulk_runs')) $this->markTestSkipped('Phase 5 migrations unavailable.');
    }

    public function test_preview_and_hash_requirements_do_not_post_financial_rows(): void
    {
        $this->isolated();
        $actor=User::where('isActive',true)->first();
        if (!$actor) $this->markTestSkipped('No test employee fixture.');
        DB::beginTransaction();
        try {
            $before=DB::table('salary_generates')->count();
            $gpf=DB::table('general_provident_funds')->count();
            $preview=null;
            try {
                $preview=app(SalaryBillPostingService::class)->previewForPosting((int)$actor->id,2026,10);
            } catch (\DomainException $e) {
                // Incomplete evidence is expected for some imported employees.
            }
            $this->assertSame($before,DB::table('salary_generates')->count());
            $this->assertSame($gpf,DB::table('general_provident_funds')->count());
            if ($preview) $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$preview['source_hash']);
            // Invalid/missing expected source hashes must not create a run.
            $this->actingAs($actor)->postJson(route('salary-bulk-runs.start'),[
                'year'=>2026,'month'=>10,'user_ids'=>[$actor->id],
                'preview_hashes'=>[],
            ])->assertStatus(422);
        } finally { DB::rollBack(); }
    }
}
