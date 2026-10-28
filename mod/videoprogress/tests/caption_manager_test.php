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
 * caption_manager_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Verifies caption language options, URL validation, and remote WebVTT import.
 *
 * @covers \mod_videoprogress\caption_manager
 */
final class caption_manager_test extends \advanced_testcase {
    /**
     * Confirms that the caption language menu contains the supported BCP 47 tags.
     *
     * @return void
     */
    public function test_language_options_include_supported_tags(): void {
        $options = caption_manager::get_language_options();

        self::assertCount(count(caption_manager::LANGUAGES), $options);
        self::assertArrayHasKey("ar-SA", $options);
        self::assertArrayHasKey("en-US", $options);
        self::assertArrayHasKey("fil-PH", $options);
        self::assertArrayHasKey("pt-BR", $options);
        self::assertArrayHasKey("pt-PT", $options);
        self::assertArrayHasKey("zh-CN", $options);
        self::assertArrayHasKey("zh-TW", $options);
        self::assertSame("pt-BR", caption_manager::normalise_language("pt-br"));
        self::assertNotSame("pt-BR", $options["pt-BR"]);
        self::assertStringContainsString("Portuguese", caption_manager::get_language_label("pt-BR"));
        $sorted = $options;
        \core_collator::asort($sorted);
        self::assertSame($sorted, $options);
    }

    /**
     * Confirms that unsupported caption languages are rejected.
     *
     * @return void
     */
    public function test_unsupported_language_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        caption_manager::normalise_language("xx-XX");
    }

    /**
     * Confirms that direct caption URLs must use HTTP(S) and a VTT or SRT extension.
     *
     * @return void
     */
    public function test_direct_caption_url_requires_vtt_or_srt_extension(): void {
        $manager = new caption_manager();

        self::assertSame(
            'https://example.test/lesson.vtt',
            $manager->validate_direct_caption_url('https://example.test/lesson.vtt')
        );
        $this->expectException(\moodle_exception::class);
        $manager->validate_direct_caption_url('https://example.test/lesson.mp4');
    }

    /**
     * Confirms that Nextcloud caption shares are converted to canonical download URLs.
     *
     * @return void
     */
    public function test_nextcloud_caption_share_is_converted_to_download_url(): void {
        $manager = new caption_manager();

        self::assertSame(
            'https://cloud.sysclass.com/index.php/s/CaptionToken/download',
            $manager->to_nextcloud_download_url('https://cloud.sysclass.com/s/CaptionToken')
        );
    }

    /**
     * Confirms that a remote WebVTT body is stored and returned to the HTML5 player.
     *
     * @return void
     */
    public function test_remote_vtt_is_stored_as_published_player_track(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $activity = $generator->create_module("videoprogress", ["course" => $course->id]);
        $context = \context_module::instance($activity->cmid);
        $user = $generator->create_and_enrol($course, "editingteacher");
        $this->setUser($user);
        $webvtt = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHello\n";

        $manager = $this->getMockBuilder(caption_manager::class)
            ->onlyMethods(["fetch_remote_content"])
            ->getMock();
        $manager->method("fetch_remote_content")->willReturn($webvtt);

        $captionid = $manager->save($activity->id, $context, (object)[
            "captionsource" => "url",
            "language" => "pt-BR",
            "captionurl" => 'https://example.test/lesson.vtt',
            "status" => "published",
        ], (int)$user->id);

        $tracks = $manager->get_published_tracks($activity->id, $context);
        self::assertCount(1, $tracks);
        self::assertSame($captionid, $tracks[0]["id"]);
        self::assertSame("pt-BR", $tracks[0]["language"]);
        self::assertNotEmpty($tracks[0]["url"]);
    }

    /**
     * Confirms that hiding a caption keeps the file but detaches it from the player.
     *
     * @return void
     */
    public function test_toggle_visibility_detaches_published_track(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $activity = $generator->create_module("videoprogress", ["course" => $course->id]);
        $context = \context_module::instance($activity->cmid);
        $user = $generator->create_and_enrol($course, "editingteacher");
        $this->setUser($user);
        $webvtt = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHello\n";

        $manager = $this->getMockBuilder(caption_manager::class)
            ->onlyMethods(["fetch_remote_content"])
            ->getMock();
        $manager->method("fetch_remote_content")->willReturn($webvtt);

        $captionid = $manager->save($activity->id, $context, (object)[
            "captionsource" => "url",
            "language" => "en-US",
            "captionurl" => 'https://example.test/lesson.vtt',
            "status" => "published",
        ], (int)$user->id);

        self::assertSame("draft", $manager->toggle_visibility($captionid, $activity->id));
        self::assertSame([], $manager->get_published_tracks($activity->id, $context));
        $listed = $manager->get_management_tracks($activity->id, $activity->cmid);
        self::assertCount(1, $listed);
        self::assertFalse($listed[0]["isvisible"]);
        self::assertStringContainsString("action=toggle", $listed[0]["toggleurl"]);
        self::assertStringContainsString("action=delete", $listed[0]["deleteurl"]);
        self::assertSame("published", $manager->toggle_visibility($captionid, $activity->id));
        self::assertCount(1, $manager->get_published_tracks($activity->id, $context));
    }

    /**
     * Confirms that a new remote URL replaces the stored caption source and WebVTT body.
     *
     * @return void
     */
    public function test_update_replaces_source_url_and_content(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $activity = $generator->create_module("videoprogress", ["course" => $course->id]);
        $context = \context_module::instance($activity->cmid);
        $user = $generator->create_and_enrol($course, "editingteacher");
        $this->setUser($user);
        $original = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHello\n";
        $updated = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nUpdated\n";

        $manager = $this->getMockBuilder(caption_manager::class)
            ->onlyMethods(["fetch_remote_content"])
            ->getMock();
        $manager->method("fetch_remote_content")->willReturnOnConsecutiveCalls($original, $updated);

        $captionid = $manager->save($activity->id, $context, (object)[
            "captionsource" => "url",
            "language" => "pt-BR",
            "captionurl" => 'https://example.test/lesson.vtt',
            "status" => "published",
        ], (int)$user->id);

        global $DB;
        $caption = $DB->get_record("videoprogress_captions", ["id" => $captionid], "*", MUST_EXIST);
        self::assertSame('https://example.test/lesson.vtt', $caption->sourceurl);

        $manager->update($caption, $context, (object)[
            "language" => "pt-BR",
            "label" => "Portuguese (Brazil)",
            "isdefault" => 1,
            "status" => "published",
            "captionurl" => 'https://example.test/updated.vtt',
            "content" => $original,
        ]);

        $caption = $DB->get_record("videoprogress_captions", ["id" => $captionid], "*", MUST_EXIST);
        self::assertSame('https://example.test/updated.vtt', $caption->sourceurl);
        $files = get_file_storage()->get_area_files($context->id, "mod_videoprogress", "caption", $captionid, "id", false);
        self::assertStringContainsString("Updated", reset($files)->get_content());
    }
}
