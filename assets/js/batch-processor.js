/* batch-processor.js - Modal-driven batch link generation with background processing */
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

  const POLL_INTERVAL_MIN = 1000; // 1 second
  const POLL_INTERVAL_MAX = 3000; // 3 seconds
  let currentPollInterval = POLL_INTERVAL_MIN;
  let pollTimer = null;
  let modalElement = null;
  let lastPollTime = 0;
  let currentMode = 'foreground'; // or 'background'
  let currentBatchId = null;

  /* ---------- Modal HTML with Mode Selector ---------- */
  function createModal() {
    const html = `
      <div class="batch-modal" id="batch-modal">
        <div class="batch-modal-content">
          <h2>
            <span class="dashicons dashicons-admin-links"></span>
            Generating Paid Links
          </h2>
          
          <!-- Mode Selector -->
          <div class="batch-mode-selector" id="batch-mode-selector">
            <label>
              <input type="radio" name="batch-mode" value="foreground" checked>
              <span class="mode-option">
                <strong>Stay on Page</strong>
                <small>Watch progress in real-time</small>
              </span>
            </label>
            <label>
              <input type="radio" name="batch-mode" value="background">
              <span class="mode-option">
                <strong>Run in Background</strong>
                <small>Get email notification when complete</small>
              </span>
            </label>
          </div>
          
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
            <button type="button" class="button button-primary" id="batch-start-btn">Start Generation</button>
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

    // Bind actions
    $("#batch-start-btn").on("click", startBatchWithMode);
    $("#batch-close-btn").on("click", closeModal);
    $("#batch-cancel-btn").on("click", cancelBatch);
    
    // Listen for mode changes
    $("input[name='batch-mode']").on("change", function() {
      currentMode = $(this).val();
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
      data: {
        action: "agent_hub_cancel_batch",
        nonce: w.agentHubData.nonce,
      },
    })
      .done(() => {
        if (w.showToast) {
          w.showToast("Cancelled", "Batch generation cancelled.", "info");
        }
        closeModal();
      })
      .fail(() => {
        closeModal();
      });
  }

  /* ---------- Update Progress UI ---------- */
  function updateProgressUI(progress) {
    const total = progress.total_posts || progress.total || 0;
    const completed = progress.completed_posts || progress.processed || 0;
    const created = progress.created_posts || progress.created || 0;
    const updated = progress.updated_posts || progress.updated || 0;
    const failed = progress.failed_posts || progress.failed || 0;

    const percent = total > 0 ? Math.round((completed / total) * 100) : 0;

    $("#batch-progress-bar").css("width", percent + "%");
    $("#batch-progress-percent").text(percent + "%");
    $("#stat-total").text(total);
    $("#stat-processed").text(completed);
    $("#stat-success").text(created + updated);
    $("#stat-failed").text(failed);
  }

  /* ---------- Poll Batch Progress ---------- */
  function pollBatchProgress() {
    const now = Date.now();
    const pollStart = now;

    const ajaxData = currentMode === 'background' 
      ? {
          action: "agent_hub_get_background_batch_progress",
          nonce: w.agentHubData.nonce,
          batch_id: currentBatchId
        }
      : {
          action: "agent_hub_process_batch",
          nonce: w.agentHubData.nonce
        };

    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 30000,
      data: ajaxData,
    })
      .done((res) => {
        if (!res || !res.success) {
          debugWarn("[batch-processor] Poll error:", res);
          closeModal();
          return;
        }

        const responseData = res.data || {};
        const progress = responseData.progress || responseData;
        
        updateProgressUI(progress);

        const isCompleted = responseData.completed || 
                           progress.status === 'completed' ||
                           (progress.completed_posts >= progress.total_posts && progress.total_posts > 0);

        if (isCompleted) {
          const created = progress.created_posts || progress.created || 0;
          const updated = progress.updated_posts || progress.updated || 0;
          const failed = progress.failed_posts || progress.failed || 0;
          const total = progress.total_posts || progress.total || 0;
          
          let msg = `Generated ${created + updated} links out of ${total} posts.`;
          if (failed > 0) {
            msg += ` (${failed} failed)`;
          }
          
          if (w.showToast) {
            w.showToast("Batch Complete", msg, "success");
          }
          setTimeout(() => {
            closeModal();
            if (w.agentHub && w.agentHub.loadContent) {
              w.agentHub.loadContent();
            }
            loadBatchHistory(); // Refresh history
          }, 3000);
        } else if (progress.status === "running" || progress.status === "pending") {
          // Adaptive polling
          const pollDuration = Date.now() - pollStart;
          if (pollDuration > 2000) {
            currentPollInterval = POLL_INTERVAL_MAX;
          } else if (pollDuration < 500) {
            currentPollInterval = POLL_INTERVAL_MIN;
          }
          
          pollTimer = setTimeout(pollBatchProgress, currentPollInterval);
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

  /* ---------- Start Batch with Selected Mode ---------- */
  function startBatchWithMode() {
    const selectedMode = $("input[name='batch-mode']:checked").val();
    currentMode = selectedMode;
    
    // Hide mode selector and start button
    $("#batch-mode-selector").hide();
    $("#batch-start-btn").hide();
    
    if (currentMode === 'background') {
      startBackgroundBatch();
    } else {
      startForegroundBatch();
    }
  }

  /* ---------- Start Background Batch ---------- */
  function startBackgroundBatch() {
    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 15000,
      data: {
        action: "agent_hub_start_background_batch",
        nonce: w.agentHubData.nonce,
        mode: 'background'
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

        currentBatchId = res.data.batch_id;
        const total = res.data.total || 0;
        
        if (w.showToast) {
          w.showToast(
            "Batch Started", 
            `Processing ${total} posts in background. You'll receive an email when complete.`, 
            "success"
          );
        }
        
        // Show polling UI
        updateProgressUI({ total_posts: total, completed_posts: 0, created_posts: 0, updated_posts: 0, failed_posts: 0 });
        
        // Start polling for background progress
        pollTimer = setTimeout(pollBatchProgress, 2000);
      })
      .fail((xhr, status, error) => {
        debugLog("[batch-processor] Start background batch failed:", status, error);
        if (w.showToast) {
          w.showToast("Error", "Failed to start background batch.", "error");
        }
        closeModal();
      });
  }

  /* ---------- Start Foreground Batch ---------- */
  function startForegroundBatch() {
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

        if (progress.status === "running" && progress.total > 0) {
          currentPollInterval = POLL_INTERVAL_MIN;
          lastPollTime = Date.now();
          pollTimer = setTimeout(pollBatchProgress, currentPollInterval);
        } else {
          if (w.showToast) {
            w.showToast("Info", "No posts available to generate links.", "info");
          }
          closeModal();
        }
      })
      .fail((xhr, status, error) => {
        debugLog("[batch-processor] Start batch failed:", status, error);
        if (w.showToast) {
          w.showToast("Error", "Failed to start batch generation.", "error");
        }
        closeModal();
      });
  }

  /* ---------- Load Batch History ---------- */
  function loadBatchHistory() {
    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "agent_hub_get_batch_history",
        nonce: w.agentHubData.nonce,
      },
    })
      .done((res) => {
        if (!res || !res.success) return;
        
        const batches = res.data.batches || [];
        renderBatchHistory(batches);
      })
      .fail(() => {
        console.warn("[batch-processor] Failed to load batch history");
      });
  }

  /* ---------- Render Batch History Table ---------- */
  function renderBatchHistory(batches) {
    const container = $("#batch-history-container");
    if (!container.length) return;

    if (batches.length === 0) {
      container.html('<p style="text-align:center;color:#666;">No batch history available.</p>');
      return;
    }

    let html = `
      <table class="batch-history-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Mode</th>
            <th>Status</th>
            <th>Total</th>
            <th>Completed</th>
            <th>Failed</th>
            <th>Success Rate</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
    `;

    batches.forEach((batch) => {
      const date = new Date(batch.created_at).toLocaleString();
      const successRate = batch.total_posts > 0 
        ? Math.round((batch.completed_posts / batch.total_posts) * 100) 
        : 0;
      
      const statusBadge = batch.status === 'completed' 
        ? '<span class="status-badge status-completed">Completed</span>'
        : batch.status === 'running'
        ? '<span class="status-badge status-running">Running</span>'
        : '<span class="status-badge status-failed">Failed</span>';
      
      const retryButton = batch.failed_posts > 0
        ? `<button class="button button-small retry-batch-btn" data-batch-id="${batch.batch_id}">Retry Failed</button>`
        : '';

      html += `
        <tr>
          <td>${date}</td>
          <td><span class="mode-badge mode-${batch.mode}">${batch.mode}</span></td>
          <td>${statusBadge}</td>
          <td>${batch.total_posts}</td>
          <td>${batch.completed_posts}</td>
          <td>${batch.failed_posts}</td>
          <td>${successRate}%</td>
          <td>${retryButton}</td>
        </tr>
      `;
    });

    html += `
        </tbody>
      </table>
    `;

    container.html(html);

    // Bind retry buttons
    $(".retry-batch-btn").on("click", function() {
      const batchId = $(this).data("batch-id");
      retryFailedBatch(batchId);
    });
  }

  /* ---------- Retry Failed Batch ---------- */
  function retryFailedBatch(batchId) {
    if (!confirm("Retry all failed posts from this batch?")) return;

    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "agent_hub_retry_failed_batch",
        nonce: w.agentHubData.nonce,
        batch_id: batchId,
      },
    })
      .done((res) => {
        if (res && res.success) {
          if (w.showToast) {
            w.showToast("Retry Started", "Failed posts have been queued for retry.", "success");
          }
          loadBatchHistory();
        } else {
          if (w.showToast) {
            w.showToast("Error", res?.data?.error || "Failed to retry batch.", "error");
          }
        }
      })
      .fail(() => {
        if (w.showToast) {
          w.showToast("Error", "Failed to retry batch.", "error");
        }
      });
  }

  /* ---------- Public API ---------- */
  w.batchProcessor = {
    start: showModal,
    loadHistory: loadBatchHistory,
  };

  // Auto-load batch history on page load if container exists
  $(document).ready(function() {
    if ($("#batch-history-container").length) {
      loadBatchHistory();
    }
  });

})(window, document, jQuery);
