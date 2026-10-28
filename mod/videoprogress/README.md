# Video Progress

Video Progress transforms videos into complete activities within the Moodle™ Software.

Instead of considering only whether the student opened the video or reached the end, the activity tracks which parts were actually played, how much unique content was watched, how much time was spent playing the video, which segments were rewatched, and where the student stopped watching.

The progress percentage is calculated based on the content actually watched. Seeking the player to the end does not cause the video to be considered complete.

Teachers and managers also have access to individual and class reports, a viewing timeline, automatic completion, gradebook integration, supporting materials, learning objectives, and content synchronized with specific moments in the video.

## Main features

- Progress based on the segments actually watched.
- Identification of watched, skipped, and rewatched parts.
- Unique content watch time.
- Total playback time, including repetitions.
- Last watched position.
- Automatic or optional playback resume.
- Completion based on a minimum percentage.
- Optional student confirmation after reaching the required percentage.
- Grade from 0 to 100 based on the percentage actually watched.
- Individual timeline.
- Aggregated class timeline.
- Identification of the most-watched segments.
- Identification of the least-watched segments.
- Identification of drop-off points.
- Optional protection against seeking to parts that have not yet been watched.
- Configurable playback speed limit.
- Viewing session logging.
- Reports with filters, sorting, and pagination.
- Export of results.
- Individual or bulk progress reset.
- Multiple subtitles for compatible videos.
- Support for WebVTT and SRT subtitles.
- Learning objectives based on Bloom's Taxonomy.
- Supporting materials in different formats.
- Content synchronized with moments in the video.
- Synchronized explanatory notes.
- Mandatory quiz during playback.
- Backup and restore.
- Moodle app integration.
- Privacy API.
- Administrative diagnostic and repair tools.
- Extensible architecture through subplugins.

## Progress based on what was actually watched

Video Progress does not use only the player's current position to determine progress.

Imagine a 10-minute video.

If the student watches from the beginning to 1 minute, seeks directly to minute 8, and watches until the end, approximately 3 minutes of unique content will actually have been watched.

The result will be approximately:

- Last position at 10 minutes.
- 3 minutes of unique content watched.
- Approximately 3 minutes of playback.
- 30% progress.

Reaching the end does not turn this result into 100%.

Skipped segments remain marked as unwatched.

### Repetitions

Rewatching part of the video does not artificially increase the completion percentage.

In a 10-minute video, if the student watches from the beginning to 5 minutes, goes back to minute 2, and watches again until minute 5, the result will be approximately:

- 5 minutes of unique content watched.
- 8 minutes of total playback.
- 50% progress.
- Higher viewing intensity between minutes 2 and 5.

This makes it possible to distinguish two important pieces of information:

**Unique content watched**

Represents which parts of the video the student actually went through at least once.

**Total playback time**

Represents how much time was spent watching the video, including repetitions.

## Video sources

Video sources are implemented as subplugins of type `videoprogresssource`.

The plugin currently includes the following sources.

### Upload

The teacher can upload the file directly to the Moodle™ Software.

Files are stored through the Moodle File API and are delivered only to users who have access to the activity.

Accepted formats:

- MP4
- WebM
- OGV
- M4V
- MOV
- HLS/M3U8

### URL

Allows the direct use of an HTTP or HTTPS URL for a video file.

Accepted formats:

- `.mp4`
- `.webm`
- `.ogv`
- `.m4v`
- `.mov`
- `.m3u8`

`.m3u8` files are handled as HLS streams.

### YouTube

Allows the use of YouTube videos through the IFrame Player API.

Different URL formats are recognized, including:

- Traditional YouTube URLs.
- `youtu.be`.
- Embed URLs.
- YouTube Shorts.
- `youtube-nocookie.com`.

The administrator can also use YouTube's privacy-enhanced domain.

### Vimeo

Allows the use of public and unlisted Vimeo videos.

URLs containing the private identifier used by unlisted videos are also supported.

## Source architecture

Sources are not hard-coded directly into the activity core.

Video Progress declares the subplugin type:

`videoprogresssource`

The following are currently included:

- `videoprogresssource_upload`
- `videoprogresssource_url`
- `videoprogresssource_youtube`
- `videoprogresssource_vimeo`
- `videoprogresssource_nextcloud`

This architecture makes it possible to add new video platforms in the future without changing the core of `mod_videoprogress`.

## Playback resume

The teacher can choose how Video Progress should behave when the student returns to the activity.

There are three options:

### Resume automatically

The video starts near the last recorded position.

### Ask

The student is given the option to continue from where they stopped or start again.

### Start from the beginning

The video always starts from the beginning.

The saved position is used only to make navigation easier.

It is not used to determine the watched percentage.

## Seeking protection

The teacher can allow or block seeking to parts of the video that have not yet been watched.

When seeking is blocked, the student can go back to an earlier region and rewatch the content normally, but cannot simply drag the player to a part that has not yet been unlocked.

Tracking also considers the actual progression of playback to prevent player seeking from being treated as content that was effectively watched.

## Playback speed

The teacher can control the maximum playback speed available to the student.

The current options are:

- No limit.
- 1x.
- 1.25x.
- 1.5x.
- 1.75x.
- 2x.

The limit is also taken into account by the validations performed during playback tracking.

## Additional player controls

There are options to make some common browser actions more difficult:

- Hide the download button when supported by the player.
- Disable Picture-in-Picture.
- Disable the context menu opened with the right mouse button.

These features are convenience and basic protection measures.

They do not constitute DRM and cannot completely prevent copying, screen recording, or content capture.

## Student timeline

Video Progress keeps a map of the segments played by the student.

The timeline makes it possible to distinguish regions that are:

- Not yet watched.
- Watched.
- Rewatched.

When a part is played more than once, the intensity of that region increases.

In addition to the visual representation, a textual description of the segments is available so that the information does not depend only on colors.

The activity can also show the student:

- Percentage watched.
- Unique content watch time.
- Total playback time.
- Video duration.
- Percentage required for completion.
- Current status.
- Last position.

## Activity completion

The teacher defines the minimum percentage that must actually be watched.

For example:

`80%`

In this case, reaching the end of the video without watching the previous parts does not complete the activity.

Completion uses the progress calculated on the server.

### Additional confirmation

Optionally, the teacher can require confirmation after the minimum percentage has been reached.

In this mode, two conditions must be met:

1. The minimum percentage must have been reached.
2. The student must provide confirmation.

Confirmation complements viewing tracking and does not replace the requirement to watch the video.

## Grade

Video Progress integrates with the Moodle™ Software gradebook.

The grade directly represents the percentage of unique content watched.

For example:

| Progress | Grade |
| --- | ---: |
| 25% | 25 |
| 50% | 50 |
| 82.5% | 82.5 |
| 100% | 100 |

The activity uses a 0 to 100 scale.

The grade item can be disabled through the activity's standard grading settings.

The native gradebook settings remain available, including the passing grade.

## Viewing sessions

In addition to consolidated progress, the plugin records playback sessions.

Each session can store information such as:

- Session start.
- End.
- Watch time.
- Initial position.
- Final position.
- Last position.
- Player state.

This information helps provide a better understanding of how the student consumed the video without relying only on the final percentage.

## Learning objectives

Video Progress has its own subplugin architecture for objectives:

`videoprogressobjective`

The currently included type is:

`videoprogressobjective_bloom`

### Bloom's Taxonomy

Each objective has a description and a Bloom's Taxonomy level.

The available levels are:

- Remember.
- Understand.
- Apply.
- Analyze.
- Evaluate.
- Create.

The teacher can add multiple objectives to the same activity.

Objectives are shown to the student together with the activity and can also be viewed in the student's individual tracking page used by the teacher.

New objective types can be implemented in the future through new `videoprogressobjective` subplugins.

## Supporting materials

Video Progress allows complementary materials to be added to the activity.

Materials are implemented through the subplugin type:

`videoprogressmaterial`

The plugin currently supports:

### PDF

Allows PDF documents related to the video to be made available.

### HTML

Allows formatted content to be created directly in the Moodle™ Software.

It can be used for additional explanations, instructions, exercises, or any complementary textual content.

### Image

Allows images to be associated with the activity content.

### Link

Allows links to other resources to be provided.

### Downloadable file

Allows complementary files to be made available to the student.

### Office

Allows working with document formats commonly used in office tools.

Materials are independent from the video and can be organized within the activity.

The architecture makes it possible to add new types through `videoprogressmaterial` subplugins.

## Content synchronized with the video

The teacher can create points at specific moments during playback.

Each point has:

- Time in the video.
- Title.
- Active or inactive state.

A single point can have associated content.

Content is implemented through subplugins:

`videoprogresscontent`

There are currently two types.

### Note

A note makes it possible to present an explanation synchronized with a specific moment in the video.

The content can use the Moodle™ Software editor and accept formatted text, images, and other elements supported by the editor.

The note can work only as a temporary message or can be configured to pause the video.

When configured to require confirmation, playback remains paused until the student acknowledges the displayed content.

It is also possible to define the display period when mandatory pause is not enabled.

### Quiz

The Quiz creates a mandatory question at a specific moment in the video.

The video is paused and the student must answer correctly before continuing.

Each quiz has:

- A question created in the editor.
- Up to four alternatives.
- At least two alternatives filled in.
- Definition of the correct alternative.
- Feedback for a correct answer.
- Feedback for an incorrect answer.

Correct-answer validation occurs on the server.

The correct alternative does not need to be sent to the student's browser in advance.

The interaction state is stored individually for each user.

## More than one content item at the same point

The Video Progress structure makes it possible to associate multiple items with the same point on the timeline.

This makes it possible to create situations such as:

**05:30**

Explanatory note → confirmation → quiz

or combine different content added by future subplugins.

## Subtitles

Video Progress allows local subtitles to be managed for sources that support this feature.

The following files are currently accepted:

- WebVTT (`.vtt`)
- SubRip (`.srt`)

SRT files are converted to WebVTT during import.

Each subtitle has:

- Language.
- Name.
- Default track indication.
- Publication status.
- Origin.
- Associated protected file.

The teacher can:

- Upload a new subtitle.
- Paste a direct WebVTT URL.
- Paste a public Nextcloud share URL.
- Choose the language from a supported list.
- Keep multiple subtitles.
- Define the default track.
- Edit the WebVTT content.
- Publish or keep a track unpublished.
- Delete a subtitle.

Only published subtitles are delivered to the player.

### YouTube and Vimeo

YouTube and Vimeo manage their own subtitles within their respective players.

Therefore, Video Progress local subtitle file management is not used for these two sources.

Subtitle support depends on the capabilities of the selected video source.

## Activity report

Users with the required permission can access a complete activity report.

The summary shows indicators such as:

- Enrolled students.
- Students who started.
- Students who never started.
- Students in progress.
- Students who completed.
- Average percentage watched.
- Average unique content watched.
- Average total playback time.
- Total playback time for the class.
- Completion rate.

## Class timeline

In addition to individual information, the plugin maintains an aggregated timeline.

It shows playback intensity throughout the video.

Segments watched or rewatched more frequently appear with greater intensity.

The aggregated map uses up to 120 regions distributed across the video duration.

This makes it possible to analyze long videos without storing a separate record for every second for every student.

## Automatic insights

Timeline analysis automatically identifies:

### Most-watched segment

Region with the highest viewing intensity.

This can help identify important or difficult parts, or parts that generated greater interest.

### Least-watched segment

Watched region with the lowest frequency.

### Drop-off point

Largest decrease in viewing identified between consecutive regions of the video.

These indicators do not attempt to pedagogically interpret the reason for the behavior.

They present data so that the teacher or manager can perform that analysis.

## Student list

The report shows the students in the activity with information such as:

- Name.
- Email.
- Profile picture.
- Percentage watched.
- Unique content time.
- Total playback time.
- Last position.
- Last viewing.
- Status.
- Individual timeline.

The list includes filters to make analysis easier.

## Individual tracking

Each student has an individual tracking page.

It shows:

- Name.
- Email.
- Percentage actually watched.
- Unique content time.
- Total playback time.
- Last position.
- Last viewing.
- Status.
- Timeline.
- Textual description of watched segments.
- Playback sessions.
- Learning objectives.
- Confirmation status.
- Confirmation date, when available.

Authorized users can also reset progress directly from this page.

## Course activity overview

Within the report, the teacher can also navigate between the Video Progress activities available in the same course.

Each video shows a summary with information such as:

- Number of students who started.
- Number of students who completed.
- Average percentage.
- Aggregated timeline.

This makes it easier to compare and navigate between videos without manually returning to each course section.

## Export

Users with the appropriate capability can export report results.

The export respects the applied filters.

The data includes consolidated student tracking information without necessarily including the entire internal structure used to build the viewing maps.

## Progress reset

Authorized users can delete the progress of:

- A specific student.
- All students matching the selected filters.

The reset also updates information that depends on progress, including the grade and activity completion.

Bulk operations require confirmation before execution.

## Multiple tabs and devices

Video Progress maintains an update sequence and independent sessions to reduce problems caused by multiple player instances being open at the same time.

This is important when the same student opens the activity:

- In two tabs.
- In different browsers.
- On another device.

Consolidated progress continues to be maintained on the server.

## Connection failures

Tracking data is sent during playback and at important player events.

The server remains the authoritative source for the consolidated state.

This model avoids relying only on the browser's local state to determine the grade or completion.

## Moodle app

Video Progress integrates with the Moodle app through the module's Mobile support.

The activity can be opened from the app in an integrated view that directs the user to the full Video Progress page.

This preserves features that depend on the player and web application, such as:

- Playback tracking.
- Synchronized content.
- Interactions.
- Timeline.
- Progress updates.

## Backup and restore

The module implements the Moodle™ Software backup and restore API.

The backup can include:

- Activity settings.
- Video source.
- Uploaded video file.
- Cover image.
- Subtitles.
- Objectives.
- Materials.
- Timeline points.
- Synchronized content.
- Playback settings.

When the backup includes user data, information related to tracking can also be preserved.

When user data is not included, the new activity does not receive the students' individual progress.

## Administrative diagnostics

Administrators have a diagnostic tool to verify Video Progress integrity.

The page analyzes information such as:

- Number of activities.
- Number of progress records.
- Orphaned records.
- Activities without a corresponding gradebook item.
- Grades inconsistent with the calculated percentage.
- Percentages outside the allowed range.
- Invalid segments.
- Invalid viewing maps.
- Gradebook formulas with missing references.

## Gradebook repair

The administrative tool can also start a repair task.

This task goes through the Video Progress activities and:

- Ensures that grade items exist.
- Resubmits calculated grades.
- Reevaluates completion status.
- Updates Moodle™ Software completion when necessary.

Gradebook formulas containing missing references are identified and shown to the administrator.

These formulas are not changed automatically.

## License

This plugin is distributed under the terms of the GNU General Public License v3 or later.

Copyright © 2026 Eduardo Kraus
