/* batch-processor.js - Modal-driven batch link generation */
(function (w, d, $) {
  "use strict";

  // Debug mode detection
  const DEBUG_MODE = w.location.hostname === 'localhost' || 
                     w.location.hostname.includes('127.0.0.1') ||
                     w.location.search.includes('debug=true');
  const debugLog = DEBUG_MODE ? console.log.bind(console) : () => {};
  const debugWarn = DEBUG_MODE ? console.warn.bind(console) : () => {};

  if (!w.agentHubData || !w.agentHubData.ajaxUrl || !w.agentHubData.nonce) {
    console.error("[batch-processor] Missing agentHubData config.");
    return;
  }

  const POLL_INTERVAL = 2000; // 2 seconds
  let pollTimer = null;
  let modalElement = null;

  /* ---------- Modal HTML ---------- */
  function createModal() {
    const html = `
      <div class="batch-modal" id="batch-modal">
        <div class="batch-modal-content">
          <h2>
            <span class="dashicons dashicons-admin-links"></span>
            Generating Paid Links
          </h2>
          
          <div class="progress-bar-container">
            <div class="progress-bar" id="batch-progress-bar"></div>
            <div class="progress-percent" id="batch-progress-percent">0%</div>
          </div>

          <div class="batch-stats">
            <div class="stat">
              <div class="stat-label">Total</div>
              <div class="stat-value info" id="stat-total">0</div>
            </div>
            <div class="stat">
              <div class="stat-label">Processed</div>
              <div class="stat-value" id="stat-processed">0</div>
            </div>
            <div class="stat">
              <div class="stat-label">Success</div>
              <div class="stat-value success" id="stat-success">0</div>
            </div>
            <div class="stat">
              <div class="stat-label">Failed</div>
              <div class="stat-value error" id="stat-failed">0</div>
            </div>
          </div>

          <div class="batch-actions">
            <button type="button" class="button" id="batch-cancel-btn">Cancel</button>
            <button type="button" class="button button-primary" id="batch-close-btn" style="display:none;">Close</button>
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

    // Bind close/cancel actions
    $("#batch-close-btn").on("click", closeModal);
    $("#batch-cancel-btn").on("click", cancelBatch);
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
  }

  /* ---------- Cancel Batch ---------- */
  function cancelBatch() {
    if (!confirm("Are you sure you want to cancel the batch generation?")) return;

    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "agent_hub_cancel_batch",
        nonce: w.agentHubData.nonce,
      },
    })
      .done(() => {
        if (w.showToast) {
          w.showToast("Cancelled", "Batch generation cancelled.", "success");
        }
      })
      .always(() => {
        closeModal();
        if (w.agentHub && w.agentHub.loadContent) {
          w.agentHub.loadContent();
        }
      });
  }

  /* ---------- Update Progress UI ---------- */
  function updateProgressUI(progress) {
    const total = parseInt(progress.total, 10) || 0;
    const processed = parseInt(progress.processed, 10) || 0;
    const successful = parseInt(progress.created, 10) || 0;
    const failed = parseInt(progress.failed, 10) || 0;
    const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

    $("#batch-progress-bar").css("width", percent + "%");
    $("#batch-progress-percent").text(percent + "%");

    $("#stat-total").text(total);
    $("#stat-processed").text(processed);
    $("#stat-success").text(successful);
    $("#stat-failed").text(failed);

    // Show close button when complete
    if (progress.status === "completed") {
      $("#batch-cancel-btn").hide();
      $("#batch-close-btn").show();
    }
  }

  /* ---------- Poll Batch Progress ---------- */
  function pollBatchProgress() {
    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 10000,
      data: {
        action: "agent_hub_process_batch",
        nonce: w.agentHubData.nonce,
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

        const progress = res.data || {};
        updateProgressUI(progress);

        // Continue polling if not complete
        if (progress.status === "running") {
          pollTimer = setTimeout(pollBatchProgress, POLL_INTERVAL);
        } else if (progress.status === "completed") {
          if (w.showToast) {
            const msg = `Generated ${progress.created || 0} links successfully. ${progress.failed || 0} failed.`;
            w.showToast("Batch Complete", msg, "success");
          }

          // Auto-close after 5 seconds
          setTimeout(() => {
            closeModal();
            if (w.agentHub && w.agentHub.loadContent) {
              w.agentHub.loadContent();
            }
          }, 5000);
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
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 15000,
      data: {
        action: "agent_hub_start_batch_generation",
        nonce: w.agentHubData.nonce,
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

        // Start polling if batch is processing
        if (progress.status === "running" && progress.total > 0) {
          pollTimer = setTimeout(pollBatchProgress, POLL_INTERVAL);
        } else {
          // No posts to process or already complete
          if (w.showToast) {
            w.showToast("Info", "No posts available to generate links.", "success");
          }
          setTimeout(closeModal, 2000);
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
