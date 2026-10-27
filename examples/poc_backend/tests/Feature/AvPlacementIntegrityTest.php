<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvPlacementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_requires_exactly_one_placement_target_and_cascades_on_deletion(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL CHECK and FK integration test.');
        }

        $now = now();
        $seriesId = DB::table('av_video_series')->insertGetId([
            'title' => 'Series', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $videoId = DB::table('av_videos')->insertGetId([
            'youtube_id' => 'integrity001', 'title' => 'Video',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $base = [
            'page_code' => 'home', 'block_code' => 'featured',
            'sort_order' => 1, 'is_enabled' => true,
            'created_at' => $now, 'updated_at' => $now,
        ];

        foreach ([
            ['series_id' => null, 'video_id' => null],
            ['series_id' => $seriesId, 'video_id' => $videoId],
        ] as $invalid) {
            try {
                DB::table('av_page_placements')->insert(array_merge($base, $invalid));
                $this->fail('Invalid placement was accepted by MySQL.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        DB::table('av_page_placements')->insert(array_merge($base, [
            'series_id' => $seriesId, 'video_id' => null,
        ]));
        DB::table('av_page_placements')->insert(array_merge($base, [
            'series_id' => null, 'video_id' => $videoId, 'sort_order' => 2,
        ]));
        $this->assertSame(2, DB::table('av_page_placements')->count());

        DB::table('av_video_series')->where('id', $seriesId)->delete();
        $this->assertSame(1, DB::table('av_page_placements')->count());
        DB::table('av_videos')->where('id', $videoId)->delete();
        $this->assertSame(0, DB::table('av_page_placements')->count());
    }
}
