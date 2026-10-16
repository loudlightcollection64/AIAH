<?php
/** Settings screen; variables in scope come from sma_settings_render(). */

if (!defined('ABSPATH')) {
    exit;
}

$sma_form_fields = [
    'signup_form_id'    => [
        'label' => 'Sign-up form',
        'help'  => 'Which of your Fluent forms is the family sign-up form? "Detect by title" uses the form whose name starts with "' . SMA_SIGNUP_FORM_PREFIX . '".',
    ],
    'volunteer_form_id' => [
        'label' => 'Volunteer form',
        'help'  => 'Which of your Fluent forms is the volunteer application? "Detect by title" uses the form whose name starts with "' . SMA_VOLUNTEER_FORM_PREFIX . '".',
    ],
];
?>
<div class="wrap sma sma-admin">
    <h1 class="sma-admin__title">So Many Angels settings</h1>

    <?php if ($notice) : ?>
        <div class="notice notice-<?php echo esc_attr($notice[0]); ?> sma-admin__notice" role="status"><p><?php echo esc_html($notice[1]); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="sma_settings_save">
        <?php wp_nonce_field('sma_settings'); ?>

        <table class="form-table" role="presentation">
            <tbody>
            <?php foreach ($sma_form_fields as $sma_key => $sma_field) :
                $sma_current = (int) $values[$sma_key];
                $sma_in_use  = (int) $effective[$sma_key];
                ?>
                <tr>
                    <th scope="row"><label for="sma-<?php echo esc_attr($sma_key); ?>"><?php echo esc_html($sma_field['label']); ?></label></th>
                    <td>
                        <select id="sma-<?php echo esc_attr($sma_key); ?>" name="<?php echo esc_attr($sma_key); ?>"<?php disabled($forms === null); ?>>
                            <option value="0"<?php selected($sma_current, 0); ?>>Detect by title</option>
                            <?php if ($forms !== null) : ?>
                                <?php foreach ($forms as $sma_id => $sma_title) : ?>
                                    <option value="<?php echo esc_attr($sma_id); ?>"<?php selected($sma_current, $sma_id); ?>><?php echo esc_html('#' . $sma_id . ' ' . $sma_title); ?></option>
                                <?php endforeach; ?>
                                <?php if ($sma_current > 0 && !isset($forms[$sma_current])) : ?>
                                    <option value="<?php echo esc_attr($sma_current); ?>" selected><?php echo esc_html('#' . $sma_current . ' (form not found)'); ?></option>
                                <?php endif; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php echo esc_html($sma_field['help']); ?></p>
                        <p class="description">
                            <?php
                            if ($forms === null) {
                                echo esc_html('Fluent Forms is not active.');
                            } elseif ($sma_in_use > 0) {
                                $sma_name = isset($forms[$sma_in_use]) ? $forms[$sma_in_use] : '';
                                echo esc_html('In use now: #' . $sma_in_use . ($sma_name !== '' ? ' ' . $sma_name : '') . ($sma_current > 0 ? '' : ' (detected)'));
                            } else {
                                echo esc_html('In use now: none found. Pick the form above.');
                            }
                            ?>
                        </p>
                    </td>
                </tr>
            <?php endforeach; ?>

                <tr>
                    <th scope="row"><label for="sma-booking_calendar_id">Booking calendar</label></th>
                    <td>
                        <select id="sma-booking_calendar_id" name="booking_calendar_id"<?php disabled($calendars === null); ?>>
                            <option value="0"<?php selected((int) $values['booking_calendar_id'], 0); ?>>First calendar</option>
                            <?php if ($calendars !== null) : ?>
                                <?php foreach ($calendars as $sma_id => $sma_title) : ?>
                                    <option value="<?php echo esc_attr($sma_id); ?>"<?php selected((int) $values['booking_calendar_id'], $sma_id); ?>><?php echo esc_html('#' . $sma_id . ' ' . $sma_title); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php echo esc_html('Which FluentBooking calendar should each event\'s booking times go in? "First calendar" uses the first one on the site.'); ?></p>
                        <?php if ($calendars === null) : ?>
                            <p class="description"><?php echo esc_html('FluentBooking is not active.'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="sma-session_minutes">Session length (minutes)</label></th>
                    <td>
                        <input type="number" id="sma-session_minutes" name="session_minutes" class="small-text" min="5" max="120" step="1" value="<?php echo esc_attr((int) $values['session_minutes']); ?>">
                        <p class="description"><?php echo esc_html('How long is each family\'s photo session? Between 5 and 120 minutes. Applies to events published or updated after you save.'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="sma-reminder_days">Reminder days</label></th>
                    <td>
                        <input type="text" id="sma-reminder_days" name="reminder_days" class="regular-text" value="<?php echo esc_attr(implode(', ', $values['reminder_days'])); ?>">
                        <p class="description"><?php echo esc_html('How many days before a family\'s booked time should their reminder emails go? Whole numbers from 1 to 60, separated by commas.'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Public events list</th>
                    <td>
                        <label for="sma-public_events_list">
                            <input type="checkbox" id="sma-public_events_list" name="public_events_list" value="1"<?php checked($values['public_events_list']); ?>>
                            <?php echo esc_html('Provide the public events list at /events/ (turn off if your own page uses that address)'); ?>
                        </label>
                        <p class="description"><?php echo esc_html('With this off, you can show the list on any page of your own by adding [sma_events] to it.'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Family data</th>
                    <td>
                        <label for="sma-editors_manage_families">
                            <input type="checkbox" id="sma-editors_manage_families" name="editors_manage_families" value="1"<?php checked($values['editors_manage_families']); ?>>
                            <?php echo esc_html('Editors may see family data'); ?>
                        </label>
                        <p class="description"><?php echo esc_html('Administrators can always see families and children. Tick this only if you trust everyone with the Editor role on this site with that too.'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Daily digest</th>
                    <td>
                        <label for="sma-digest_enabled">
                            <input type="hidden" name="digest_enabled" value="0">
                            <input type="checkbox" id="sma-digest_enabled" name="digest_enabled" value="1"<?php checked($values['digest_enabled']); ?>>
                            <?php echo esc_html('Email me a short summary each morning when something needs me'); ?>
                        </label>
                        <p class="description"><?php echo esc_html('Families waiting for approval, events in the next three weeks, set-up problems and new volunteers. Counts only, never a child\'s details. Sent only on days with something to say.'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="sma-digest_time">Digest time</label></th>
                    <td>
                        <input type="time" id="sma-digest_time" name="digest_time" value="<?php echo esc_attr($values['digest_time']); ?>">
                        <p class="description"><?php echo esc_html('In the site\'s time zone. It arrives with the first visit to the site after this time.'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="sma-digest_recipient">Send the digest to</label></th>
                    <td>
                        <input type="email" id="sma-digest_recipient" name="digest_recipient" class="regular-text" value="<?php echo esc_attr($values['digest_recipient']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        <p class="description"><?php echo esc_html('Leave empty to use the site\'s admin email (' . get_option('admin_email') . ').'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="sma-approval_nudge_days">Waiting too long after</label></th>
                    <td>
                        <input type="number" id="sma-approval_nudge_days" name="approval_nudge_days" class="small-text" min="1" max="60" step="1" value="<?php echo esc_attr((int) $values['approval_nudge_days']); ?>"> <?php echo esc_html('days'); ?>
                        <p class="description"><?php echo esc_html('Families awaiting approval for longer than this are called out in the digest.'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="sma-btn sma-btn--primary">Save settings</button>
        </p>
    </form>

    <?php // After the settings form, so its nonce is the page's first _wpnonce (tests read it). ?>
    <h2 class="sma-admin__heading" id="sma-digest">Daily digest</h2>
    <p data-digest-next="<?php echo esc_attr($digest['next']); ?>">
        <?php
        echo esc_html($digest['next'] !== ''
            ? 'Next digest: ' . $digest['next'] . ', to ' . $digest['recipient'] . ' (sent only if something needs you).'
            : 'The daily digest is off.');
        ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sma-admin__form" data-form="digest-test">
        <input type="hidden" name="action" value="sma_digest_test">
        <?php wp_nonce_field('sma_digest_test', '_sma_digest_nonce'); ?>
        <button type="submit" class="sma-btn">Send a test digest now</button>
        <span class="description"><?php echo esc_html('Sends today\'s digest to ' . $digest['recipient'] . ' now, even if nothing needs you.'); ?></span>
    </form>

    <h2 class="sma-admin__heading" id="sma-site-checks">Site checks</h2>
    <p class="description"><?php echo esc_html('Things to fix on this site, before or after going live. This list only reads your settings; it changes nothing.'); ?></p>
    <ul class="sma-site-checks" data-site-checks="<?php echo esc_attr(count($checks)); ?>">
        <?php
        $sma_check_badges = [
            'pass' => ['sma-badge sma-badge--approved', 'OK'],
            'warn' => ['sma-badge sma-badge--awaiting-approval', 'Fix'],
            'info' => ['sma-badge', 'Note'],
        ];
        foreach ($checks as $sma_check) :
            $sma_badge = isset($sma_check_badges[$sma_check['status']]) ? $sma_check_badges[$sma_check['status']] : $sma_check_badges['info'];
            ?>
            <li data-check="<?php echo esc_attr($sma_check['id']); ?>" data-status="<?php echo esc_attr($sma_check['status']); ?>">
                <span class="<?php echo esc_attr($sma_badge[0]); ?>"><?php echo esc_html($sma_badge[1]); ?></span>
                <strong><?php echo esc_html($sma_check['title']); ?></strong>
                <?php if ($sma_check['how'] !== '') : ?>
                    <br><span class="description"><?php echo esc_html($sma_check['how']); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
