<?php
$range = $report['range'];
$kpis = $report['kpis'];
$trend = $report['trend'];
$topRevenue = max(1, (int) ($report['top_products'][0]['revenue'] ?? 0));
?>
<section class="admin-page-head reports-page-head">
  <div><span class="eyebrow">Business intelligence</span><h1>Sales &amp; Reports</h1><p><?= e($range['label']) ?> · <?= e((string) setting('business_city','Long Island, NY')) ?> · <?= e($range['timezone_name']) ?></p></div>
  <a class="button button-secondary" href="<?= url('admin/orders') ?>"><i data-lucide="history"></i>All order history</a>
</section>

<form class="admin-panel report-filter" method="get">
  <label><span>Date range</span><select name="range" data-report-range><?php foreach(['today'=>'Today','yesterday'=>'Yesterday','7d'=>'Last 7 days','30d'=>'Last 30 days','month'=>'This month','custom'=>'Custom dates'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $range['key']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label data-report-custom <?= $range['key']==='custom'?'':'hidden' ?>><span>From</span><input type="date" name="from" value="<?= e($range['from']) ?>"></label>
  <label data-report-custom <?= $range['key']==='custom'?'':'hidden' ?>><span>To</span><input type="date" name="to" value="<?= e($range['to']) ?>"></label>
  <button class="button button-primary" type="submit"><i data-lucide="calendar-search"></i>Update report</button>
</form>

<section class="report-kpi-grid">
  <?php foreach([
    ['Revenue',money($kpis['revenue']),'banknote'],
    ['Paid sales',number_format($kpis['orders']),'receipt-text'],
    ['Items sold',number_format($kpis['units']),'package-check'],
    ['Average sale',money($kpis['average']),'circle-dollar-sign'],
    ['New customers',number_format($kpis['new_customers']),'user-round-plus'],
    ['Tax collected',money($kpis['tax']),'landmark'],
  ] as [$label,$value,$icon]): ?><article><span><i data-lucide="<?= e($icon) ?>"></i><?= e($label) ?></span><strong><?= e($value) ?></strong></article><?php endforeach; ?>
</section>

<section class="admin-panel revenue-chart-panel">
  <div class="panel-head"><div><span class="eyebrow">Revenue movement</span><h2><?= e($range['label']) ?></h2></div><div class="chart-summary"><span>Discounts <b><?= money($kpis['discounts']) ?></b></span><span>Fees <b><?= money($kpis['fees']) ?></b></span></div></div>
  <?php if ($kpis['orders']): ?><div class="revenue-chart" role="img" aria-label="Revenue chart for <?= e($range['label']) ?>"><?php foreach($trend as $point): ?><div class="revenue-column" title="<?= e($point['label']) ?>: <?= money((int)$point['revenue']) ?> from <?= (int)$point['orders'] ?> sales"><span><i style="height:<?= max(3,(int)$point['percent']) ?>%"></i></span><small><?= e($point['label']) ?></small></div><?php endforeach; ?></div><?php else: ?><div class="empty-state small"><i data-lucide="chart-no-axes-column-increasing"></i><h3>No paid sales in this range</h3><p>All orders remain available in order history.</p></div><?php endif; ?>
</section>

<section class="report-grid">
  <article class="admin-panel report-ranking-panel"><div class="panel-head"><div><span class="eyebrow">What sells</span><h2>Top products</h2></div></div><?php if($report['top_products']): ?><div class="report-ranking"><?php foreach($report['top_products'] as $index=>$row): ?><div><span class="ranking-number"><?= $index+1 ?></span><span><strong><?= e($row['label']) ?></strong><small><?= (int)$row['units'] ?> items · <?= money((int)$row['revenue']) ?></small><i style="width:<?= max(4,(int)round($row['revenue']/$topRevenue*100)) ?>%"></i></span></div><?php endforeach; ?></div><?php else: ?><p class="report-empty">No product sales yet.</p><?php endif; ?></article>
  <article class="admin-panel report-ranking-panel"><div class="panel-head"><div><span class="eyebrow">Exact choices</span><h2>Top options</h2></div></div><?php if($report['top_variants']): ?><div class="report-simple-list"><?php foreach($report['top_variants'] as $row): ?><div><span><strong><?= e($row['label']) ?></strong><small><?= (int)$row['units'] ?> sold</small></span><b><?= money((int)$row['revenue']) ?></b></div><?php endforeach; ?></div><?php else: ?><p class="report-empty">No option sales yet.</p><?php endif; ?></article>
  <article class="admin-panel"><div class="panel-head"><div><span class="eyebrow">Catalog mix</span><h2>Categories</h2></div></div><div class="report-simple-list"><?php foreach($report['top_categories'] as $row): ?><div><span><strong><?= e($row['label']) ?></strong><small><?= (int)$row['units'] ?> items</small></span><b><?= money((int)$row['revenue']) ?></b></div><?php endforeach; ?></div></article>
  <article class="admin-panel"><div class="panel-head"><div><span class="eyebrow">How customers pay</span><h2>Payment methods</h2></div></div><div class="report-breakdown"><?php foreach($report['payments'] as $row): ?><div><span><strong><?= e($row['label']) ?></strong><small><?= (int)$row['orders'] ?> sales</small></span><b><?= (int)$row['percent'] ?>%</b><i><em style="width:<?= (int)$row['percent'] ?>%"></em></i></div><?php endforeach; ?></div></article>
  <article class="admin-panel"><div class="panel-head"><div><span class="eyebrow">Where sales happen</span><h2>POS vs online</h2></div></div><div class="report-breakdown"><?php foreach($report['sources'] as $row): ?><div><span><strong><?= e($row['label']) ?></strong><small><?= money((int)$row['revenue']) ?></small></span><b><?= (int)$row['percent'] ?>%</b><i><em style="width:<?= (int)$row['percent'] ?>%"></em></i></div><?php endforeach; ?></div></article>
  <article class="admin-panel"><div class="panel-head"><div><span class="eyebrow">Team activity</span><h2>Sales by staff</h2></div></div><div class="report-simple-list"><?php foreach($report['staff'] as $row): ?><div><span><strong><?= e($row['label']) ?></strong><small><?= (int)$row['orders'] ?> sales</small></span><b><?= money((int)$row['revenue']) ?></b></div><?php endforeach; ?></div></article>
</section>

<section class="report-grid report-grid-bottom">
  <article class="admin-panel"><div class="panel-head"><div><span class="eyebrow">Timing</span><h2>Busiest hours</h2></div></div><div class="report-chip-list"><?php foreach($report['busy_hours'] as $row): ?><span><strong><?= e($row['label']) ?></strong><small><?= (int)$row['orders'] ?> sales</small></span><?php endforeach; ?><?php if(!$report['busy_hours']): ?><p class="report-empty">Not enough sales yet.</p><?php endif; ?></div></article>
  <article class="admin-panel"><div class="panel-head"><div><span class="eyebrow">Weekly rhythm</span><h2>Busiest days</h2></div></div><div class="report-simple-list compact"><?php foreach(array_slice($report['busy_days'],0,5) as $row): ?><div><strong><?= e($row['label']) ?></strong><b><?= (int)$row['orders'] ?> sales</b></div><?php endforeach; ?></div></article>
  <article class="admin-panel report-history-note"><i data-lucide="database-backup"></i><div><span class="eyebrow">Nothing is erased</span><h2>Complete history stays available</h2><p><?= number_format($kpis['all_orders']) ?> total orders were recorded in this range, including <?= number_format($kpis['cancelled_orders']) ?> cancelled orders worth <?= money($kpis['cancelled_cents']) ?>. Cancelled orders are excluded from revenue.</p></div></article>
</section>
