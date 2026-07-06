<?php

/** @var Mailika\Support\View $view */
/** @var Throwable|null $exception */
?>
<section class="content-pane single-pane">
  <div class="empty-state">
    <h1>Something went wrong</h1>
    <p>The request could not be completed.</p>
  </div>
  <?php if ($exception instanceof Throwable): ?>
    <pre class="debug-block"><?= $view->escape($exception->getMessage() . "\n" . $exception->getTraceAsString()) ?></pre>
  <?php endif; ?>
</section>
