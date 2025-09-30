<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Library for mod_learningmap
 *
 * @package     mod_learningmap
 * @copyright 2021-2024, ISB Bayern
 * @author      Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_learningmap\cachemanager;
use mod_learningmap\helper;

/**
 * Array with all features the plugin supports for advanced settings. Might be moved
 * to another place when in use somewhere else.
 */
define('LEARNINGMAP_FEATURES', [
    'hidepaths',
    'hidestroke',
    'usecheckmark',
    'pulse',
    'hover',
    'showall',
    'showtext',
    'slicemode',
    'showwaygone',
]);

/**
 * Adds a new learningmap instance
 *
 * @param stdClass $data learningmap record
 * @return int
 */
function learningmap_add_instance($data): int {
    global $DB;
    $learningmapid = $DB->insert_record('learningmap', $data);

    $context = context_module::instance($data->coursemodule);
    if (!empty($data->backgroundfile)) {
        file_save_draft_area_files(
            $data->backgroundfile,
            $context->id,
            'mod_learningmap',
            'background',
            0,
            ['subdirs' => 0, 'maxfiles' => 1]
        );
    }
    return $learningmapid;
}

/**
 * Updates a learningmap instance
 *
 * @param stdClass $data learningmap record
 * @return int
 */
function learningmap_update_instance($data): int {
    global $DB;
    $data->id = $data->instance;

    $context = context_module::instance($data->coursemodule);
    if (!empty($data->backgroundfile)) {
        // Delete old background files.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_learningmap', 'background');
        file_save_draft_area_files(
            $data->backgroundfile,
            $context->id,
            'mod_learningmap',
            'background',
            0,
            ['subdirs' => 0, 'maxfiles' => 1]
        );
    }

    // Don't save changes to svgcode and placestore if the general part of the editing form was not shown.
    if (!empty($data->showonly) && $data->showonly != 'general') {
        unset($data->svgcode);
        unset($data->placestore);
    }

    return $DB->update_record("learningmap", $data);
}

/**
 * Deletes a learningmap instance
 *
 * @param integer $id learningmap record
 * @return int
 */
function learningmap_delete_instance($id): int {
    global $DB;
    return $DB->delete_records("learningmap", ["id" => $id]);
}

/**
 * Returns whether a feature is supported by this module.
 * @uses FEATURE_IDNUMBER
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know or string for the module purpose.
 */
function learningmap_supports($feature) {
    // For versions <4.0.
    if (!defined('FEATURE_MOD_PURPOSE')) {
        define('FEATURE_MOD_PURPOSE', 'mod_purpose');
        define('MOD_PURPOSE_CONTENT', 'content');
    }
    switch ($feature) {
        case FEATURE_IDNUMBER:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Delivers the background file
 *
 * @package mod_learningmap
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function learningmap_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): ?bool {
    require_course_login($course, true, $cm);

    $fullpath = "/$context->id/mod_learningmap/$filearea/" . implode('/', $args);

    $fs = get_file_storage();
    if ((!$file = $fs->get_file_by_hash(sha1($fullpath))) || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, false, $options);
}

/**
 * Adds custom completion info to the course module info
 *
 * @param cm_info $cm
 * @return cached_cm_info|null
 */
function learningmap_get_coursemodule_info($cm): cached_cm_info {
    global $DB;

    if (!$map = $DB->get_record('learningmap', ['id' => $cm->instance], 'completiontype')) {
        return null;
    }

    $result = new cached_cm_info();

    $completiontypes = [
        'nocompletion',
        'completion_with_one_target',
        'completion_with_all_targets',
        'completion_with_all_places',
    ];

    if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC && $map->completiontype > 0) {
        $result->customdata['customcompletionrules'][$completiontypes[$map->completiontype]] = 1;
    }

    return $result;
}

/**
 * Removes the view link if showdescription is set.
 *
 * @param cm_info $cm
 * @return void
 */
function learningmap_cm_info_dynamic(cm_info $cm): void {
    // Decides whether to display the link.
    if (helper::show_map_on_course_page($cm)) {
        $cm->set_no_view_link(true);
    }
}

/**
 * Generates course module info, especially the map (as intro).
 * If showdescription is set, this function outputs the intro and the map.
 *
 * @param cm_info $cm
 * @return void
 */
function learningmap_cm_info_view(cm_info $cm): void {
    global $DB, $OUTPUT;

    $learningmap = $DB->get_record('learningmap', ['id' => $cm->instance]);
    $intro = '';
    $groupdropdown = '';
    $mapcontainer = '';

    if (!empty($cm->showdescription) && !empty($learningmap->intro)) {
        $intro = format_module_intro('learningmap', $learningmap, $cm->id);
    }

    // Only show map on course page if showmaponcoursepage is set.
    if (helper::show_map_on_course_page($cm)) {
        if (!empty($cm->groupmode)) {
            $groupdropdown = groups_print_activity_menu(
                $cm,
                new moodle_url(
                    '/course/view.php',
                    ['id' => $cm->get_course()->id, 'section' => $cm->sectionnum],
                    'module-' . $cm->id
                ),
                true
            );
            // Since there is no way to replace the core string just for this dropdown
            // we have to change it in this ugly way.
            $groupdropdown = str_replace(
                get_string('allparticipants'),
                get_string('ownprogress', 'mod_learningmap'),
                $groupdropdown
            );
        }

        $contentbeforemap = $groupdropdown . $intro;
        $hascontentbeforemap = !empty($contentbeforemap);

        $mapcontainer = $OUTPUT->render_from_template(
            'mod_learningmap/rendercontainer',
            [
                'cmId' => $cm->id,
                'enableLiveUpdater' => true,
                'contentbeforemap' => $contentbeforemap,
                'hascontentbeforemap' => $hascontentbeforemap,
            ]
        );

        $cm->set_custom_cmlist_item(true);
    }

    $cm->set_content($mapcontainer, true);
}

/**
 * Returns all course module ids for places of a certain learning map.
 * @param cm_info $cm course module object for the learning map
 * @return array
 */
function learningmap_get_place_cm(cm_info $cm): array {
    global $DB;
    $map = $DB->get_record("learningmap", ["id" => $cm->instance], 'placestore');
    $modules = [];
    $placestore = json_decode($map->placestore);
    foreach ($placestore->places as $p) {
        if ($p->linkedActivity != null) {
            $modules[] = $p->linkedActivity;
        }
    }
    return $modules;
}

/**
 * Returns the code of the learningmap.
 *
 * @param cm_info $cm
 * @return string
 */
function learningmap_get_learningmap(cm_info $cm): string {
    global $DB, $OUTPUT;

    $context = context_module::instance($cm->id);

    $map = $DB->get_record("learningmap", ["id" => $cm->instance]);

    if (empty($map->svgcode)) {
        $mapcode = $map->intro;
        $filearea = 'intro';
    } else {
        $mapcode = $map->svgcode;
        $filearea = 'background';
    }

    $svg = file_rewrite_pluginfile_URLS(
        $mapcode,
        'pluginfile.php',
        $context->id,
        'mod_learningmap',
        $filearea,
        0
    );

    $placestore = json_decode($map->placestore, true);
    // If auto mode is enabled, synthesize a placestore from course activities per current user.
    if (!empty($map->automode)) {
        $placestore = learningmap_build_auto_placestore($cm);
    }

    $group = (empty($cm->groupmode) ? 0 : groups_get_activity_group($cm, true));

    $worker = new \mod_learningmap\mapworker($svg, $placestore, $cm, false, $group);
    $worker->process_map_objects();
    $worker->remove_tags_before_svg();

    $allowedfilters = explode(',', str_replace(' ', '', get_config('mod_learningmap', 'allowedfilters')));

    $filtermanager = filter_manager::instance();
    $skipfilters = array_diff(array_keys(filter_get_active_in_context($cm->context)), $allowedfilters);

    return(
        $filtermanager->filter_text(
            $OUTPUT->render_from_template(
                'mod_learningmap/mapcontainer',
                ['mapcode' => $worker->get_svgcode()]
            ),
            $cm->context,
            ['trusted' => true, 'noclean' => true],
            $skipfilters
        )
    );
}

/**
 * Build a synthesized placestore from course activities for auto mode.
 * Simple vertical layout, one node per visible course module.
 *
 * @param cm_info $cm learningmap course module
 * @return array placestore-like structure
 */
function learningmap_build_auto_placestore(cm_info $cm): array {
    global $CFG, $USER;
    $modinfo = get_fast_modinfo($cm->get_course(), $USER->id);

    $places = [];
    $paths = [];
    $targetplaces = [];
    $startingplaces = [];

    $index = 0;
    // Randomized coordinates within canvas bounds.
    $width = 800;
    $height = 600;
    $radius = 10;
    $padding = 40;
    srand((int)($cm->instance + $USER->id * 31));

    // Build ordered list of course modules in course order (by section, then position).
    $orderedcms = [];
    foreach ($modinfo->get_sections() as $sectionnum => $sectioncmids) {
        foreach ($sectioncmids as $scmid) {
            $orderedcms[] = $modinfo->get_cm($scmid);
        }
    }

    // We want predictable course order for path chaining but random positions for nodes.
    foreach ($orderedcms as $cmobj) {
        $cmid = $cmobj->id;
        if ($cmobj->deletioninprogress != 0) {
            continue;
        }
        // Skip the learningmap instance itself and modules not displayable.
        if ($cmobj->id == $cm->id) {
            continue;
        }
        if ($CFG->branch >= 500 && !plugin_supports('mod', $cmobj->modname, FEATURE_CAN_DISPLAY, true)) {
            continue;
        }
        // Basic visibility filter: include items available to the user or with a view page.
        // We include hidden-but-available items too; final visibility handled in mapworker.

        $placeid = 'auto_place_' . $cmid;
        $linkid = 'auto_link_' . $cmid;

        // Random position.
        $x = $padding + rand($radius, $width - $padding - $radius);
        $y = $padding + rand($radius, $height - $padding - $radius);

        $places[] = [
            'id' => $placeid,
            'x' => $x,
            'y' => $y,
            'radius' => $radius,
            'linkedActivity' => $cmid,
            'linkId' => $linkid,
        ];

        if ($index === 0) {
            $startingplaces[] = $placeid;
        }

        $index++;
    }

    // Connect nodes sequentially by course order.
    $orderedplaceids = array_map(function($cmobj) { return 'auto_place_' . $cmobj->id; }, $orderedcms);
    $orderedplaceids = array_values(array_filter($orderedplaceids, function($pid) use ($places) {
        foreach ($places as $p) { if ($p['id'] === $pid) { return true; } }
        return false;
    }));
    for ($i = 0; $i < count($orderedplaceids) - 1; $i++) {
        $paths[] = [
            'id' => 'auto_path_' . $i,
            'sid' => $orderedplaceids[$i],
            'fid' => $orderedplaceids[$i + 1],
        ];
    }

    $options = [
        'editmode' => false,
        'mapid' => 'auto_' . $cm->instance,
        'places' => $places,
        'paths' => $paths,
        'startingplaces' => $startingplaces,
        'targetplaces' => $targetplaces,
        'hidepaths' => 0,
        'showall' => 1,
        'showtext' => 1,
        'hover' => 0,
        'pulse' => 0,
        'usecheckmark' => 1,
        'slicemode' => 0,
    ];

    // Use a minimal SVG skeleton if none provided.
    $options['svgcode'] = '';
    return $options;
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * At this moment nothing needs to be done.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function learningmap_reset_userdata($data) {
    return [];
}

/**
 * Generate "back to map" buttons in activities linked to from the learning map.
 *
 * Needs to be completely removed as soon as we do not support moodle 4.3 and below anymore. The logic has been moved to
 * {@see \mod_learningmap\local\hook_callbacks::inject_backlinks_into_activity_header} from moodle 4.4 on.
 *
 * @return void
 */
function learningmap_before_http_headers() {
    global $PAGE, $OUTPUT;

    if (get_config('mod_learningmap', 'backlinkallowed') == 0) {
        return;
    }

    if ($PAGE->context->contextlevel != CONTEXT_MODULE) {
        return '';
    }

    try {
        $cache = cache::make('mod_learningmap', 'backlinks');

        $cachekey = $PAGE->cm->id;
        $backlinks = $cache->get($cachekey);

        if (!$backlinks) {
            // If the cache is not yet filled, fill it for the current course. This is a fallback in
            // case the task has not been executed yet or was not fast enough. Should only happen after
            // cache purging.
            if (!$cache->get('fillstate')) {
                cachemanager::build_backlink_cache($PAGE->course->id);
            }
            // Try again to get the backlinks.
            $backlinks = $cache->get($cachekey);
        }

        $backlinktext = '';

        if (empty($backlinks)) {
            return;
        }

        $modinfo = get_fast_modinfo($PAGE->course);
        foreach ($backlinks as $backlink) {
            $cminfo = $modinfo->get_cm($backlink['cmid']);
            if ($cminfo->available != 0 && $cminfo->uservisible) {
                $backlinktext .= $OUTPUT->render_from_template('learningmap/backtomap', $backlink);
            }
        }

        if ($backlinktext) {
            $activityheader = $PAGE->activityheader->export_for_template($OUTPUT);
            $PAGE->activityheader->set_description($activityheader['description'] . $backlinktext);
        }
    } catch (Exception $e) {
        debugging($e->getMessage());
    }
    return '';
}
