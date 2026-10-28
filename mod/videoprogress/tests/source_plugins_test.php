<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * source_plugins_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Verifies discovery and normalization behavior supplied by video source subplugins.
 *
 * @covers \mod_videoprogress\source\manager
 * @covers \videoprogresssource_url\plugin
 * @covers \videoprogresssource_youtube\plugin
 * @covers \videoprogresssource_vimeo\plugin
 * @covers \videoprogresssource_nextcloud\plugin
 */
final class source_plugins_test extends \advanced_testcase {
    /**
     * Confirms that the source manager discovers every source bundled with the activity.
     *
     * @return void
     */
    public function test_manager_discovers_bundled_sources(): void {
        $manager = new source\manager();
        $plugins = $manager->get_plugins();

        self::assertArrayHasKey("upload", $plugins);
        self::assertArrayHasKey("url", $plugins);
        self::assertArrayHasKey("youtube", $plugins);
        self::assertArrayHasKey("vimeo", $plugins);
        self::assertArrayHasKey("nextcloud", $plugins);
        foreach ($plugins as $plugin) {
            self::assertInstanceOf(source\plugin_base::class, $plugin);
        }
        self::assertSame("upload", $manager->get_default_source());
    }

    /**
     * Confirms that direct files and HLS manifests are normalized without trusting arbitrary URLs.
     *
     * @return void
     */
    public function test_url_source_normalizes_html5_and_hls_urls(): void {
        $plugin = new \videoprogresssource_url\plugin();

        self::assertSame(
            ["url" => 'https://example.test/video.webm', "hls" => false],
            $plugin->build_config((object)["videourl" => 'https://example.test/video.webm'])
        );
        self::assertSame(
            ["url" => 'https://example.test/live/stream.m3u8?token=example', "hls" => true],
            $plugin->build_config((object)["videourl" => 'https://example.test/live/stream.m3u8?token=example'])
        );
    }

    /**
     * Confirms that unsupported direct URL extensions are rejected by the owning subplugin.
     *
     * @return void
     */
    public function test_url_source_rejects_unsupported_extensions(): void {
        $this->expectException(\moodle_exception::class);
        (new \videoprogresssource_url\plugin())->build_config(
            (object)["videourl" => 'https://example.test/page.html']
        );
    }

    /**
     * Confirms that all documented YouTube URL forms produce the same normalized identifier.
     *
     * @return void
     */
    public function test_youtube_source_normalizes_supported_urls(): void {
        $plugin = new \videoprogresssource_youtube\plugin();
        $urls = [
            'https://youtube.com/watch?v=AbCdEf12345',
            'https://www.youtube.com/watch?v=AbCdEf12345',
            'https://youtu.be/AbCdEf12345',
            'https://youtube.com/embed/AbCdEf12345',
        ];

        foreach ($urls as $url) {
            self::assertSame(["id" => "AbCdEf12345"], $plugin->build_config((object)["youtubeurl" => $url]));
        }
    }

    /**
     * Confirms that public and unlisted Vimeo URLs retain both the identifier and privacy hash.
     *
     * @return void
     */
    public function test_vimeo_source_normalizes_public_and_unlisted_urls(): void {
        $plugin = new \videoprogresssource_vimeo\plugin();

        self::assertSame(
            ["id" => "123456789", "hash" => ''],
            $plugin->build_config((object)["vimeourl" => 'https://vimeo.com/123456789'])
        );
        self::assertSame(
            ["id" => "123456789", "hash" => "a1B2c3"],
            $plugin->build_config((object)["vimeourl" => 'https://vimeo.com/123456789/a1B2c3'])
        );
        self::assertSame(
            ["id" => "123456789", "hash" => "a1B2c3"],
            $plugin->build_config((object)["vimeourl" => 'https://player.vimeo.com/video/123456789?h=a1B2c3'])
        );
    }

    /**
     * Confirms that the manager stores only server-normalized source configuration and legacy compatibility data.
     *
     * @return void
     */
    public function test_manager_normalizes_activity_record(): void {
        $record = (object)[
            "videosource" => "youtube",
            "youtubeurl" => 'https://youtu.be/AbCdEf12345',
        ];

        (new source\manager())->normalise_record($record);

        self::assertSame("AbCdEf12345", $record->videourl);
        self::assertSame(["id" => "AbCdEf12345"], json_decode($record->sourceconfig, true));
    }

    /**
     * Confirms that public Nextcloud share URLs are converted to canonical download URLs.
     *
     * @return void
     */
    public function test_nextcloud_source_converts_share_urls_to_download_urls(): void {
        $plugin = new \videoprogresssource_nextcloud\plugin();

        self::assertSame(
            'https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF/download',
            $plugin->to_download_url('https://cloud.sysclass.com/s/HgrGTatBqTnEpSF')
        );
        self::assertSame(
            'https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF/download',
            $plugin->to_download_url('https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF')
        );
        self::assertSame(
            'https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF/download',
            $plugin->to_download_url('https://cloud.sysclass.com/s/HgrGTatBqTnEpSF/download')
        );
        self::assertSame(
            'https://cloud.sysclass.com:8443/index.php/s/HgrGTatBqTnEpSF/download?path=%2Flesson.mp4',
            $plugin->to_download_url(
                'https://cloud.sysclass.com:8443/s/HgrGTatBqTnEpSF?path=%2Flesson.mp4'
            )
        );
    }

    /**
     * Confirms that a reachable Nextcloud video share is stored as a playable download URL.
     *
     * @return void
     */
    public function test_nextcloud_source_build_config_returns_download_url(): void {
        $plugin = $this->create_nextcloud_plugin_with_probe([
            "status" => 200,
            "contenttype" => "video/mp4",
            "acceptranges" => "bytes",
        ]);

        self::assertSame(
            [
                "shareurl" => 'https://cloud.sysclass.com/s/HgrGTatBqTnEpSF',
                "url" => 'https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF/download',
                "hls" => false,
            ],
            $plugin->build_config((object)[
                "nextcloudurl" => 'https://cloud.sysclass.com/s/HgrGTatBqTnEpSF',
            ])
        );
    }

    /**
     * Confirms that Nextcloud HLS playlists select the HLS adapter without requiring a file extension.
     *
     * @return void
     */
    public function test_nextcloud_source_detects_hls_content_type(): void {
        $plugin = $this->create_nextcloud_plugin_with_probe([
            "status" => 200,
            "contenttype" => 'application/vnd.apple.mpegurl',
            "acceptranges" => '',
        ]);

        $config = $plugin->build_config((object)[
            "nextcloudurl" => 'https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF',
        ]);
        self::assertTrue($config["hls"]);
        self::assertSame(
            'https://cloud.sysclass.com/index.php/s/HgrGTatBqTnEpSF/download',
            $config["url"]
        );
    }

    /**
     * Confirms that unsupported Nextcloud share URLs are rejected before any media probe.
     *
     * @return void
     */
    public function test_nextcloud_source_rejects_unsupported_urls(): void {
        $this->expectException(\moodle_exception::class);
        (new \videoprogresssource_nextcloud\plugin())->to_download_url(
            'https://example.test/video.mp4'
        );
    }

    /**
     * Confirms that a Nextcloud share that is not a video is rejected after the HEAD probe.
     *
     * @return void
     */
    public function test_nextcloud_source_rejects_non_video_content_type(): void {
        $plugin = $this->create_nextcloud_plugin_with_probe([
            "status" => 200,
            "contenttype" => "application/pdf",
            "acceptranges" => "bytes",
        ]);

        $this->expectException(\moodle_exception::class);
        $plugin->build_config((object)[
            "nextcloudurl" => 'https://cloud.sysclass.com/s/HgrGTatBqTnEpSF',
        ]);
    }

    /**
     * Returns a Nextcloud source whose remote HEAD probe is replaced with a fixture.
     *
     * @param array $probe Simulated HTTP probe result.
     * @return \videoprogresssource_nextcloud\plugin Test double of the Nextcloud source.
     */
    private function create_nextcloud_plugin_with_probe(array $probe): \videoprogresssource_nextcloud\plugin {
        $plugin = $this->getMockBuilder(\videoprogresssource_nextcloud\plugin::class)
            ->onlyMethods(["fetch_probe"])
            ->getMock();
        $plugin->method("fetch_probe")->willReturn($probe);
        return $plugin;
    }
}
