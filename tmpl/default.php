<?php

/**
 * @package     MlSignup
 * @license     GNU General Public License version 2 or later
 *
 * Template override: templates/YOUR_TEMPLATE/html/plg_system_mlsignup/default.php
 *
 * @var array $displayData
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$uid        = $displayData['uid'];
$privacyUrl = $displayData['privacyUrl'];

$privacyLabel = Text::_('PLG_SYSTEM_MLSIGNUP_PRIVACY_LABEL');
$privacy      = $privacyUrl !== ''
    ? '<a href="' . $e($privacyUrl) . '" target="_blank" rel="noopener">' . $e($privacyLabel) . '</a>'
    : $e($privacyLabel);
?>
<div class="mlsignup">
    <form class="mlsignup__form" method="post" action="<?php echo $e($displayData['endpoint']); ?>" novalidate
          data-msg-working="<?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_WORKING')); ?>"
          data-msg-error="<?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_ERR_GENERIC')); ?>">

        <div class="mlsignup__field">
            <label for="<?php echo $uid; ?>-email"><?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_EMAIL')); ?></label>
            <input type="email" id="<?php echo $uid; ?>-email" name="email" required maxlength="254" autocomplete="email">
        </div>

        <?php if ($displayData['showName']) : ?>
            <div class="mlsignup__field">
                <label for="<?php echo $uid; ?>-name"><?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_NAME')); ?></label>
                <input type="text" id="<?php echo $uid; ?>-name" name="name" maxlength="100" autocomplete="given-name">
            </div>
        <?php endif; ?>

        <div class="mlsignup__hp" aria-hidden="true">
            <label for="<?php echo $uid; ?>-website">Website</label>
            <input type="text" id="<?php echo $uid; ?>-website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <label class="mlsignup__consent">
            <input type="checkbox" name="consent" value="1" required>
            <span><?php echo Text::sprintf('PLG_SYSTEM_MLSIGNUP_CONSENT', $privacy); ?></span>
        </label>

        <p class="mlsignup__hint"><?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_HINT')); ?></p>

        <button type="submit" class="mlsignup__button btn btn-primary"><?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_SUBMIT')); ?></button>

        <div class="mlsignup__status" role="status" aria-live="polite"></div>
        <noscript><p class="mlsignup__status" data-state="error"><?php echo $e(Text::_('PLG_SYSTEM_MLSIGNUP_NOSCRIPT')); ?></p></noscript>
    </form>
</div>
