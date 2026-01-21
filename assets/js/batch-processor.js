/* batch-processor.js - Modal-driven batch link generation */
(function (w, d, $) {
  "use strict";

  // Debug mode detection
  const DEBUG_MODE = w.location.hostname === 'localhost' || 
                     w.location.hostname.includes('127.0.0.1') ||
                     w.location.search.includes('debug=true');
  const debugLog = DEBUG_MODE ? console.log.bind(console) : () => {};
  const debugWarn = DEBUG_MODE ? console.warn.bind(console) : () => {};

  if (!w.angreessen49Data || !w.angreessen49Data.ajaxUrl || !w.angreessen49Data.nonce) {
    console.error("[batch-processor] Missing angreessen49Data config.");
    return;
  }

  const POLL_INTERVAL = 1000; // 1 second for faster UI updates
  let pollTimer = null;
  let modalElement = null;

  /* ---------- SVG Icons ---------- */
  const ICONS = {
    link: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
    </svg>`,
    stop: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
    </svg>`,
    check: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>`
  };

  /* ---------- Modal HTML ---------- */
  function createModal() {
    const html = `
      <div class="batch-modal" id="batch-modal">
        <div class="batch-modal-content">
          <!-- Close button -->
          <button type="button" class="batch-close-btn" id="batch-close-btn">&times;</button>
          
          <!-- Icon with pulse -->
          <div class="batch-icon-container">
            <div class="batch-icon-pulse"></div>
            <div class="batch-icon">
              ${ICONS.link}
            </div>
          </div>
          
          <!-- Header -->
          <h2 class="batch-title">Generating Paid Links</h2>
          <p class="batch-subtitle" id="batch-subtitle">Processing your content to create monetizable links for AI agents.</p>
          
          <!-- Progress bar -->
          <div class="progress-bar-container">
            <div class="progress-bar" id="batch-progress-bar"></div>
          </div>
          <div class="progress-percent" id="batch-progress-percent">0%</div>

          <!-- Stats (4 columns) -->
          <div class="batch-stats batch-stats-4">
            <div class="stat stat-total">
              <div class="stat-label">Total</div>
              <div class="stat-value" id="stat-total">0</div>
            </div>
            <div class="stat stat-success">
              <div class="stat-label"><span class="stat-icon">✓</span> Created</div>
              <div class="stat-value" id="stat-success">0</div>
            </div>
            <div class="stat stat-existing">
              <div class="stat-label"><span class="stat-icon">↻</span> Existing</div>
              <div class="stat-value" id="stat-existing">0</div>
            </div>
            <div class="stat stat-skipped">
              <div class="stat-label"><span class="stat-icon">⊘</span> Failed</div>
              <div class="stat-value" id="stat-skipped">0</div>
            </div>
          </div>

          <!-- Stop button -->
          <div class="batch-actions">
            <button type="button" class="batch-stop-btn" id="batch-action-btn">
              ${ICONS.stop}
              <span>Stop Process</span>
            </button>
          </div>
        </div>
      </div>
    `;
    return $(html);
  }

  /* ---------- Show Modal ---------- */
  function showModal() {
    if (modalElement) modalElement.remove();
    modalElement = createModal();
    $("body").append(modalElement);

    // Bind close button (X)
    $("#batch-close-btn").on("click", function() {
      if ($("#batch-action-btn").hasClass("is-done")) {
        closeModal();
      } else {
        cancelBatch();
      }
    });

    // Bind action button - starts as "Stop" (cancel), changes to "Done" (close) when complete
    $("#batch-action-btn").on("click", function() {
      if ($(this).hasClass("is-done")) {
        closeModal();
      } else {
        cancelBatch();
      }
    });
  }

  /* ---------- Close Modal ---------- */
  function closeModal() {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    if (modalElement) {
      modalElement.fadeOut(200, function () {
        $(this).remove();
      });
      modalElement = null;
    }
    // Refresh content view
    if (w.angreessen49 && w.angreessen49.loadContent) {
      w.angreessen49.loadContent();
    }
  }

  /* ---------- Cancel Batch ---------- */
  function cancelBatch() {
    if (!confirm("Are you sure you want to cancel the batch generation?")) return;

    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    $.ajax({
      url: w.angreessen49Data.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "angreessen49_cancel_batch",
        nonce: w.angreessen49Data.nonce,
      },
    })
      .done(() => {
        if (w.showToast) {
          w.showToast("Cancelled", "Batch generation cancelled.", "success");
        }
      })
      .always(() => {
        closeModal();
      });
  }

  /* ---------- Update Progress UI ---------- */
  function updateProgressUI(progress) {
    const total = parseInt(progress.total, 10) || 0;
    const processed = parseInt(progress.processed, 10) || 0;
    const created = parseInt(progress.created, 10) || 0;
    const alreadyLinked = parseInt(progress.already_linked, 10) || 0;
    const failed = parseInt(progress.failed, 10) || 0;
    const percent = total > 0 ? Math.round((processed / total) * 100) : 100;

    $("#batch-progress-bar").css("width", percent + "%");
    $("#batch-progress-percent").text(percent + "%");

    $("#stat-total").text(total);
    $("#stat-success").text(created);
    $("#stat-existing").text(alreadyLinked);
    $("#stat-skipped").text(failed);

    // Change button to "Done" when complete
    if (progress.status === "completed") {
      markAsComplete();
    }
  }

  /* ---------- Mark Modal as Complete ---------- */
  function markAsComplete() {
    const $btn = $("#batch-action-btn");
    $btn.addClass("is-done");
    $btn.html(`${ICONS.check}<span>Done</span>`);
    
    // Stop the pulse animation
    $(".batch-icon-pulse").css("animation", "none");
  }

  /* ---------- Show "All Links Generated" State ---------- */
  function showAllLinksGenerated() {
    // Update UI to show everything is done
    $("#batch-progress-bar").css("width", "100%");
    $("#batch-progress-percent").text("100%");
    $("#batch-subtitle").text("All your posts already have paid links generated.");
    $(".batch-title").text("All Links Generated");
    
    // Update stats to show 0 pending
    $("#stat-total").text("0");
    $("#stat-success").text("0");
    $("#stat-existing").text("0");
    $("#stat-skipped").text("0");
    
    // Mark as complete immediately
    markAsComplete();
    
    // Show toast
    if (w.showToast) {
      w.showToast("Info", "All posts already have paid links.", "success");
    }
  }

  /* ---------- Poll Batch Progress ---------- */
  function pollBatchProgress() {
    $.ajax({
      url: w.angreessen49Data.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 30000,
      data: {
        action: "angreessen49_process_batch",
        nonce: w.angreessen49Data.nonce,
      },
    })
      .done((res) => {
        if (!res || !res.success) {
          debugLog("[batch-processor] Error polling:", res);
          if (w.showToast) {
            w.showToast("Error", res?.data?.message || "Failed to process batch.", "error");
          }
          closeModal();
          return;
        }

        // Handle both response structures defensively:
        // - start_batch: res.data = {status, total, processed, ...}
        // - process_next_batch: res.data = {success, completed, progress: {...}}
        const responseData = res.data || {};
        const progress = responseData.progress || responseData;
        const isCompleted = responseData.completed || progress.status === 'completed';

        console.log('[batch-processor] Extracted progress:', progress);
        console.log('[batch-processor] Completed?', isCompleted, 'Status:', progress.status);

        updateProgressUI(progress);

        if (isCompleted || progress.status === "completed") {
          const created = progress.created || 0;
          const existing = progress.already_linked || 0;
          const failed = progress.failed || 0;
          
          if (w.showToast) {
            let msg = `Created ${created} new links.`;
            if (existing > 0) msg += ` ${existing} already existed.`;
            if (failed > 0) msg += ` ${failed} failed.`;
            w.showToast("Batch Complete", msg, "success");
          }
          
          // Clear the overview cache so dashboard refreshes with new counts
          if (w.clearOverviewCache) {
            w.clearOverviewCache();
          }
          
          // Mark as complete but DON'T auto-close - let user click Done
          markAsComplete();
        } else if (progress.status === "running") {
          pollTimer = setTimeout(pollBatchProgress, POLL_INTERVAL);
        } else {
          console.warn('[batch-processor] Unexpected state:', progress);
          closeModal();
        }
      })
      .fail((xhr, status, error) => {
        debugLog("[batch-processor] Poll failed:", status, error);
        if (w.showToast) {
          w.showToast("Error", "Network error during batch processing.", "error");
        }
        closeModal();
      });
  }

  /* ---------- Start Batch Generation ---------- */
  function startBatchGeneration() {
    showModal();

    $.ajax({
      url: w.angreessen49Data.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 15000,
      data: {
        action: "angreessen49_start_batch_generation",
        nonce: w.angreessen49Data.nonce,
      },
    })
      .done((res) => {
        if (!res || !res.success) {
          if (w.showToast) {
            w.showToast("Error", res?.data?.message || "Failed to start batch.", "error");
          }
          closeModal();
          return;
        }

        const progress = res.data || {};
        updateProgressUI(progress);

        // Handle the case where there are no posts to process
        if (progress.total === 0 || progress.total === "0") {
          showAllLinksGenerated();
          // DON'T auto-close - let user see the state and click Done
          return;
        }

        // Start polling if batch is running
        if (progress.status === "running") {
          pollTimer = setTimeout(pollBatchProgress, POLL_INTERVAL);
        }
      })
      .fail((xhr, status, error) => {
        debugLog("[batch-processor] Start failed:", status, error);
        if (w.showToast) {
          w.showToast("Error", "Network error. Please try again.", "error");
        }
        closeModal();
      });
  }

  /* ---------- Hijack Bulk Generate Button ---------- */
  $(d).ready(function () {
    const $bulkBtn = $("#bulk-generate-links");
    if (!$bulkBtn.length) {
      debugWarn("[batch-processor] Bulk generate button not found.");
      return;
    }

    // Remove existing click handlers from admin.js
    $bulkBtn.off("click");

    // Attach our modal-driven handler
    $bulkBtn.on("click", function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      startBatchGeneration();
    });

    debugLog("[batch-processor] Modal batch processor initialized.");
  });
})(window, document, jQuery);
