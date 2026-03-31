/**
 * @file
 * ATmosphere backfill functionality.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.atmosphereBackfill = {
    attach: function (context) {
      once('atmosphere-backfill', '#atmosphere-backfill-start', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          startBackfill(button);
        });
      });
    }
  };

  function startBackfill(button) {
    button.disabled = true;
    button.value = Drupal.t('Backfilling...');

    var progress = document.getElementById('atmosphere-backfill-progress');
    progress.innerHTML = '<div class="progress-message">' + Drupal.t('Counting unsynced content...') + '</div>';

    fetch(Drupal.url('admin/config/services/atmosphere/backfill/count'), {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      }
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (data.count === 0) {
        progress.innerHTML = '<div class="progress-message">' + Drupal.t('All content is already synced.') + '</div>';
        button.disabled = false;
        button.value = Drupal.t('Start Backfill');
        return;
      }

      progress.innerHTML =
        '<div class="progress-bar"><div class="progress-bar__fill" style="width: 0%"></div></div>' +
        '<div class="progress-message">' + Drupal.t('Syncing 0 of @total...', {'@total': data.count}) + '</div>';

      processBatch(data.nids, 0, data.count, progress, button);
    })
    .catch(function (err) {
      progress.innerHTML = '<div class="progress-message messages messages--error">' + Drupal.t('Error: @msg', {'@msg': err.message}) + '</div>';
      button.disabled = false;
      button.value = Drupal.t('Start Backfill');
    });
  }

  function processBatch(nids, processed, total, progress, button) {
    var token = document.querySelector('meta[name="csrf-token"]');
    var headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    };

    fetch(Drupal.url('admin/config/services/atmosphere/backfill/batch'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify({ nids: nids })
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      var batchProcessed = data.results ? data.results.length : 0;
      processed += batchProcessed;

      var pct = Math.min(100, Math.round((processed / total) * 100));
      progress.querySelector('.progress-bar__fill').style.width = pct + '%';
      progress.querySelector('.progress-message').textContent =
        Drupal.t('Synced @processed of @total...', {'@processed': processed, '@total': total});

      if (processed < total) {
        // Fetch next batch.
        fetch(Drupal.url('admin/config/services/atmosphere/backfill/count'), {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        })
        .then(function (response) { return response.json(); })
        .then(function (countData) {
          if (countData.nids && countData.nids.length > 0) {
            processBatch(countData.nids, processed, total, progress, button);
          } else {
            finishBackfill(processed, total, progress, button);
          }
        });
      } else {
        finishBackfill(processed, total, progress, button);
      }
    })
    .catch(function (err) {
      progress.querySelector('.progress-message').textContent =
        Drupal.t('Error during backfill: @msg', {'@msg': err.message});
      button.disabled = false;
      button.value = Drupal.t('Start Backfill');
    });
  }

  function finishBackfill(processed, total, progress, button) {
    progress.querySelector('.progress-bar__fill').style.width = '100%';
    progress.querySelector('.progress-message').textContent =
      Drupal.t('Backfill complete. @processed items synced.', {'@processed': processed});
    button.disabled = false;
    button.value = Drupal.t('Start Backfill');
  }

})(Drupal, once);
