/**
 * InsightX Offload — admin UI behaviour.
 * Tabs, toggle switches, live URL preview, AJAX save/test, and the client
 * for the server-side bulk jobs (start/pause/resume/cancel + polling).
 */
(($) => {
  "use strict";

  const cfg = window.isxsAdmin || {};
  const connections = cfg.connections || {};
  const providerLogos = cfg.providerLogos || {};

  /* ------------------------------------------------------------------
   * Storage/Migrate tab provider pickers — these only pick which already-
   * configured connection (from the Connections tab) to use as the
   * destination/source; they no longer hold their own endpoint/bucket/key
   * fields. The destination picker exists twice (Storage tab, and the
   * "ไป" column on the Migrate tab) and is kept in sync in both places.
   * ---------------------------------------------------------------- */

  const badgeStateFor = (slug) => {
    const c = connections[slug];
    if (!c || !c.configured) {
      return {
        state: "unknown",
        text: "ยังไม่ได้ตั้งค่า provider นี้ — ไปตั้งค่าที่แท็บ “การเชื่อมต่อ”",
      };
    }
    if (c.status === "ok") {
      return { state: "ok", text: "เชื่อมต่อสำเร็จ" };
    }
    if (c.status === "error") {
      return { state: "error", text: c.statusMessage || "เชื่อมต่อไม่สำเร็จ" };
    }
    return { state: "unknown", text: "ยังไม่ได้ทดสอบการเชื่อมต่อ" };
  };

  const renderConnBadge = ($badge, slug) => {
    const b = badgeStateFor(slug);
    $badge.attr("data-state", b.state).find("span").last().text(b.text);
  };

  // Live-update the Storage/Delivery card heads (logo, provider label and
  // bucket/region) plus the picker trigger whenever the destination
  // provider changes — no page reload needed.
  const renderProviderHeads = (slug) => {
    const c = connections[slug] || {};
    const label = c.label || slug;
    const logo = providerLogos[slug] || "";

    const $sLogo = $("#isxs-storage-head-logo");
    const $sSub = $("#isxs-storage-head-sub");
    if ($sLogo.length) $sLogo.html(logo);
    if ($sSub.length) {
      let t = label;
      if (c.bucket) t += ` · ${c.bucket}`;
      if (c.region) t += ` · ${c.region}`;
      $sSub.text(t);
    }

    const $dLogo = $("#isxs-delivery-head-logo");
    const $dSub = $("#isxs-delivery-head-sub");
    if ($dLogo.length) $dLogo.html(logo);
    if ($dSub.length) {
      $dSub.text(c.bucket ? `${label} · ${c.bucket}` : `${label} · (ยังไม่ตั้ง bucket)`);
    }

    $("#isxs-dest-provider-selected-logo").html(logo);
    $("#isxs-dest-provider-selected-label").text(label);

    $("#isxs-dest-provider-menu .isxs-picker-option")
      .removeClass("is-selected")
      .attr("aria-selected", "false")
      .filter(`[data-provider="${slug}"]`)
      .addClass("is-selected")
      .attr("aria-selected", "true");
  };

  const selectDestinationProvider = (slug) => {
    $("#isxs-provider, #isxs-provider-migrate-mirror").val(slug);
    $(".isxs-provider-card, .isxs-dest-provider-card")
      .removeClass("is-active")
      .filter(`[data-provider="${slug}"]`)
      .addClass("is-active");
    renderProviderHeads(slug);
    renderConnBadge($("#isxs-dest-status-badge"), slug);
    updateUrlPreview();
    saveSettingsDebounced();
  };

  const selectSourceProvider = (slug) => {
    $("#isxs-source-provider").val(slug);
    $(".isxs-source-provider-card")
      .removeClass("is-active")
      .filter(`[data-provider="${slug}"]`)
      .addClass("is-active");
    saveSettingsDebounced();
  };

  $(".isxs-provider-card, .isxs-dest-provider-card").on("click", (e) => {
    selectDestinationProvider($(e.currentTarget).data("provider"));
  });
  $(".isxs-source-provider-card").on("click", (e) => {
    selectSourceProvider($(e.currentTarget).data("provider"));
  });

  // Storage/Migrate tab pickers only allow selecting a provider that's
  // actually verified (status 'ok') — this was previously computed only
  // at page load (PHP), so a fresh save+test on the Connections tab
  // never unlocked its picker cards without a hard refresh. Called after
  // every Connections-tab save to keep them in sync live.
  const updateProviderPickerState = (slug, verified) => {
    $(
      `.isxs-provider-card[data-provider="${slug}"], .isxs-source-provider-card[data-provider="${slug}"], .isxs-dest-provider-card[data-provider="${slug}"]`,
    )
      .prop("disabled", !verified)
      .toggleClass("is-unconfigured", !verified);
    // Keep the Storage-card picker's options in sync — a provider that
    // isn't verified yet can't be picked as the destination there either.
    $(`#isxs-dest-provider-menu .isxs-picker-option[data-provider="${slug}"]`)
      .toggleClass("is-disabled", !verified)
      .attr("aria-disabled", verified ? "false" : "true");
  };

  /* ------------------------------------------------------------------
   * Tabs
   * ---------------------------------------------------------------- */

  const switchTab = (tab) => {
    $(".isxs-nav-item").removeClass("is-active");
    $(`.isxs-nav-item[data-tab="${tab}"]`).addClass("is-active");
    $(".isxs-tab").removeClass("is-active");
    $(`[data-tab-panel="${tab}"]`).addClass("is-active");
  };

  $(".isxs-nav-item").on("click", (e) => {
    switchTab($(e.currentTarget).data("tab"));
  });

  /* ------------------------------------------------------------------
   * Toggle switches
   * ---------------------------------------------------------------- */

  const isOn = (setting) =>
    $('.isxs-switch[data-setting="' + setting + '"]')
      .first()
      .hasClass("is-on");

  $(".isxs-switch").on("click", (e) => {
    const $btn = $(e.currentTarget);
    // Switches without a data-setting are not settings toggles — nothing
    // to auto-save (defensive: any future non-settings switches go through
    // their own handler).
    if (!$btn.attr("data-setting")) {
      return;
    }
    $btn
      .toggleClass("is-on")
      .attr("aria-checked", $btn.hasClass("is-on") ? "true" : "false");
    syncPrefixField();
    updateUrlPreview();
    // Connection-card toggles (path_style/send_public_acl) save via
    // their own card's "บันทึก" button instead — only the Storage/
    // Migrate tab toggles auto-save here.
    if (!$btn.closest(".isxs-connection-card").length) {
      saveSettingsDebounced();
    }
  });

  const syncPrefixField = () => {
    $("#isxs-prefix-field").toggle(isOn("use_prefix"));
  };

  /* ------------------------------------------------------------------
   * Live URL preview
   * ---------------------------------------------------------------- */

  const currentDomain = () => {
    const cdn = $.trim($("#isxs-cdn-domain").val());
    if (cdn) {
      return cdn.replace(/^https?:\/\//, "").replace(/\/+$/, "");
    }

    const c = connections[$("#isxs-provider").val()] || {};
    const bucket = c.bucket || "my-bucket";
    const endpoint = c.endpoint || "";
    if (endpoint) {
      const host = endpoint.replace(/^https?:\/\//, "").replace(/\/+$/, "");
      return c.pathStyle ? host + "/" + bucket : bucket + "." + host;
    }
    const region = c.region || "us-east-1";
    return bucket + ".s3." + region + ".amazonaws.com";
  };

  const updateUrlPreview = () => {
    const now = new Date();
    const ym = now.getFullYear() + "/" + ("0" + (now.getMonth() + 1)).slice(-2);
    const prefix = $.trim($("#isxs-prefix").val()) || "wp-content/uploads/";

    $("[data-url-preview]").each((index, el) => {
      const $p = $(el);

      const part = (name, value, on) => {
        const $el = $p.find(`[data-part="${name}"]`);
        $el.toggleClass("is-off", !on);
        $el.find("code").text(on ? value : "—");
      };

      part("scheme", isOn("force_https") ? "https://" : "http://", true);
      part("domain", currentDomain(), true);
      part("prefix", prefix, isOn("use_prefix"));
      part("yearmonth", ym + "/", isOn("use_year_month"));
      part("version", "48291736/", isOn("use_object_version"));
    });

    // Assets preview (tab ทรัพยากร) — CDN domain fronts the site, so only
    // scheme/domain/path apply; the path stays the theme/plugin file.
    const $asset = $('[data-url-preview="assets"]');
    if ($asset.length) {
      const assetDomain = $.trim($("#isxs-assets-cdn-domain").val());
      const mediaDomain = $.trim($("#isxs-cdn-domain").val());
      const domain = (assetDomain || mediaDomain)
        .replace(/^https?:\/\//, "")
        .replace(/\/+$/, "");
      const on = isOn("assets_enabled") && !!domain;
      const apart = (name, value, active) => {
        const $el = $asset.find(`[data-part="${name}"]`);
        $el.toggleClass("is-off", !active);
        $el.find("code").text(active ? value : "—");
      };
      apart("ascheme", isOn("assets_force_https") ? "https://" : "http://", true);
      apart("adomain", domain || "cdn.example.com", on);
      apart("apath", "wp-content/themes/ชื่อธีม/style.css", on);
    }
  };

  $("#isxs-prefix, #isxs-cdn-domain, #isxs-assets-cdn-domain").on(
    "input",
    updateUrlPreview,
  );
  // 'change' (fires on blur/Enter, not per keystroke) auto-saves these —
  // 'input' above only drives the live preview.
  $(
    "#isxs-prefix, #isxs-cdn-domain, #isxs-assets-cdn-domain, #isxs-source-prefix, #isxs-source-public-url",
  ).on("change", () => {
    saveSettingsDebounced();
  });

  /* ------------------------------------------------------------------
   * Secret key masking — show a decoy value (so the field visibly looks
   * filled in) instead of the real secret or a blank box. The real value
   * never touches the DOM; typing replaces the decoy, leaving it alone
   * keeps whatever was last saved.
   * ---------------------------------------------------------------- */

  const SECRET_MASK = "•".repeat(16);
  const $secretFields = $(".isxs-conn-secret-key");

  const maskSecretField = ($el, hasSecret) => {
    $el.data("has-secret", !!hasSecret);
    if (hasSecret) {
      $el.val(SECRET_MASK).data("masked", true);
    } else {
      $el.val("").data("masked", false);
    }
  };

  const secretValueToSubmit = ($el) => ($el.data("masked") ? "" : $el.val());

  $secretFields.each((index, el) => {
    const $el = $(el);
    maskSecretField($el, $el.attr("data-has-secret") === "1");
  });

  $secretFields
    .on("focus", (e) => {
      const $el = $(e.currentTarget);
      if ($el.data("masked")) {
        $el.val("").data("masked", false);
      }
    })
    .on("blur", (e) => {
      const $el = $(e.currentTarget);
      if ($.trim($el.val()) === "" && $el.data("has-secret")) {
        $el.val(SECRET_MASK).data("masked", true);
      }
    });

  /* ------------------------------------------------------------------
   * Save settings
   * ---------------------------------------------------------------- */

  const collectSettings = () => {
    const data = {
      action: "isxs_save_settings",
      nonce: cfg.nonce,
      provider: $("#isxs-provider").val(),
      prefix: $.trim($("#isxs-prefix").val()),
      cdn_domain: $.trim($("#isxs-cdn-domain").val()),
      assets_cdn_domain: $.trim($("#isxs-assets-cdn-domain").val()),
      source_provider: $("#isxs-source-provider").val(),
      source_prefix: $.trim($("#isxs-source-prefix").val()),
      source_public_base_url: $.trim($("#isxs-source-public-url").val()),
    };
    // Connection-card toggles (path_style/send_public_acl) are scoped
    // per provider card and saved via isxs_save_connection instead —
    // exclude them here so this settings-only save doesn't submit
    // whichever card's toggle happens to match by data-setting name.
    $(".isxs-switch")
      .not(".isxs-connection-card .isxs-switch")
      .each((index, el) => {
        const key = $(el).data("setting");
        if (!(key in data)) {
          data[key] = $(el).hasClass("is-on") ? 1 : 0;
        }
      });
    return data;
  };

  /* ------------------------------------------------------------------
   * Toast — small transient feedback for auto-saves (no more manual
   * save button/bar to show inline status in).
   * ---------------------------------------------------------------- */

  let toastTimer = null;
  const showToast = (text, isError) => {
    const $toast = $("#isxs-toast");
    clearTimeout(toastTimer);
    $toast.text(text).toggleClass("is-error", !!isError).addClass("is-visible");
    toastTimer = setTimeout(() => {
      $toast.removeClass("is-visible");
    }, 2500);
  };

  const saveSettings = () => {
    showToast(cfg.i18n.saving, false);

    $.post(cfg.ajaxUrl, collectSettings())
      .done((res) => {
        if (res && res.success) {
          // Every response carries a fresh nonce — swap it in so a page
          // left open past the nonce lifetime keeps saving instead of
          // failing with an unexplained error.
          if (res.data.nonce) {
            cfg.nonce = res.data.nonce;
          }
          showToast(res.data.message, false);
          $secretFields.each((index, el) => {
            const $el = $(el);
            const willHaveSecret =
              $el.data("masked") || $.trim($el.val()) !== "";
            maskSecretField($el, willHaveSecret);
          });
          if (res.data.destination_changed) {
            // The destination provider just switched — every
            // tool's resume state pointed at the previous one,
            // and the ring/counts shown were computed against it too.
            resetToolsUI();
          }
        } else {
          showToast(
            (res && res.data && res.data.message) || cfg.i18n.error,
            true,
          );
        }
      })
      .fail((xhr) => {
        showToast(
          xhr && (xhr.status === 403 || xhr.status === 401)
            ? cfg.i18n.sessionExpired
            : cfg.i18n.error,
          true,
        );
      });
  };

  // Coalesces rapid changes (e.g. flipping two toggles back to back)
  // into one request — each save always sends the full current snapshot,
  // so debouncing is purely a request-count optimization.
  let saveSettingsTimer = null;
  const saveSettingsDebounced = () => {
    clearTimeout(saveSettingsTimer);
    saveSettingsTimer = setTimeout(saveSettings, 300);
  };

  /* ------------------------------------------------------------------
   * Connections tab — one card per provider, save + test in one click.
   * ---------------------------------------------------------------- */

  $(".isxs-conn-save-btn").on("click", (e) => {
    const $btn = $(e.currentTarget);
    const $card = $btn.closest(".isxs-connection-card");
    const slug = $card.data("provider");
    const $badge = $card.find("[data-conn-badge]");
    const $secret = $card.find(".isxs-conn-secret-key");

    $btn.prop("disabled", true).text(cfg.i18n.saving);

    const data = {
      action: "isxs_save_connection",
      nonce: cfg.nonce,
      provider: slug,
      endpoint: $.trim($card.find(".isxs-conn-endpoint").val()),
      region: $.trim($card.find(".isxs-conn-region").val()),
      bucket: $.trim($card.find(".isxs-conn-bucket").val()),
      access_key: $.trim($card.find(".isxs-conn-access-key").val()),
      secret_key: secretValueToSubmit($secret),
      path_style: $card
        .find('.isxs-switch[data-setting="path_style"]')
        .hasClass("is-on")
        ? 1
        : 0,
      send_public_acl: $card
        .find('.isxs-switch[data-setting="send_public_acl"]')
        .hasClass("is-on")
        ? 1
        : 0,
    };

    $.post(cfg.ajaxUrl, data)
      .done((res) => {
        if (res && res.success) {
          if (res.data.nonce) {
            cfg.nonce = res.data.nonce;
          }
          const willHaveSecret =
            $secret.data("masked") || $.trim($secret.val()) !== "";
          maskSecretField($secret, willHaveSecret);

          connections[slug] = connections[slug] || {};
          connections[slug].endpoint = data.endpoint;
          connections[slug].region = data.region;
          connections[slug].bucket = data.bucket;
          connections[slug].pathStyle = !!data.path_style;
          connections[slug].configured = res.data.configured;
          connections[slug].status = res.data.connected
            ? "ok"
            : res.data.configured
              ? "error"
              : "unknown";
          connections[slug].statusMessage = res.data.message;

          renderConnBadge($badge, slug);
          $card.toggleClass("is-unconfigured", !res.data.configured);
          updateProviderPickerState(slug, !!res.data.connected);

          if (res.data.affects_destination) {
            renderConnBadge($("#isxs-dest-status-badge"), slug);
            updateUrlPreview();
          }
          if (res.data.affects_destination || res.data.affects_source) {
            resetToolsUI();
          }
        } else {
          // No badge state fits "we tried to save and it broke" — the
          // top badge is reserved for real destination reachability,
          // so a request-level failure surfaces on the message text
          // only, not by faking a connection state.
          $badge
            .attr("data-state", "error")
            .find("span")
            .last()
            .text((res && res.data && res.data.message) || cfg.i18n.error);
        }
      })
      .fail((xhr) => {
        // A 403 here is a lapsed nonce or a session that was logged out
        // elsewhere, not a bucket problem — saying "เชื่อมต่อไม่สำเร็จ"
        // sent people hunting through their storage credentials for a
        // fault that was only ever in this tab.
        const expired = xhr && (xhr.status === 403 || xhr.status === 401);
        $badge
          .attr("data-state", "error")
          .find("span")
          .last()
          .text(expired ? cfg.i18n.sessionExpired : cfg.i18n.error);
        if (expired) {
          showToast(cfg.i18n.sessionExpired, true);
        }
      })
      .always(() => {
        $btn.prop("disabled", false).text(cfg.i18n.connSave);
      });
  });

  /* ------------------------------------------------------------------
   * Stats helpers
   * ---------------------------------------------------------------- */

  const applyStats = (stats) => {
    if (!stats) {
      return;
    }
    // Bucket-aware: reflects progress toward the CURRENTLY configured
    // destination bucket (the same rule as the tools' denominators — see
    // ISXM_Tools::tool_total()), not a lifetime total across every
    // bucket ever used. `in_bucket` counts every attachment physically
    // on that destination (including files that arrived via Migrate) —
    // that's the honest "how much is really on the cloud" number;
    // `offloaded` would undercount migrated files.
    const onBucket = stats.in_bucket || 0;
    const total = stats.total || 0;
    const percent = total > 0 ? Math.floor((onBucket / total) * 100) : 0;
    $("#isxs-ring").css("--pct", percent);
    $("#isxs-ring-label").text(percent + "%");
    $("#isxs-stat-offloaded").text(onBucket.toLocaleString());
    $("#isxs-stat-total").text(total.toLocaleString());

    // Offload Status widget in the header — same numbers, compact.
    $("#isxs-status-percent").text(percent + "%");
    $("#isxs-status-fill").css("width", percent + "%");
    $("#isxs-status-offloaded").text(onBucket.toLocaleString());
    $("#isxs-status-total").text(total.toLocaleString());
    $("#isxs-status-onbucket").text(onBucket.toLocaleString());
    const statusRemaining = Math.max(total - onBucket, 0);
    $("#isxs-status-remaining").text(statusRemaining.toLocaleString());
    $("#isxs-status-remaining-num").text(statusRemaining.toLocaleString());

    const failed = stats.failed || 0;
    $("#isxs-stat-failed").text(failed.toLocaleString());
    $("#isxs-stat-failed-line").prop("hidden", failed <= 0);

    const partial = stats.partial || 0;
    $("#isxs-stat-partial").text(partial.toLocaleString());
    $("#isxs-stat-partial-line").prop("hidden", partial <= 0);

    syncRetryCard(stats);
  };

  /* ------------------------------------------------------------------
   * Bulk tools — server-side jobs
   *
   * The browser no longer schedules the work: it asks the server to start
   * a job and then only watches it. Everything that used to live in this
   * file (the resume cursor, the processed count, the denominator) now
   * lives in one job record per tool, which is why closing the tab, an
   * expired nonce or a second admin opening the page can no longer lose a
   * run's place — and why every viewer sees the same numbers.
   * ---------------------------------------------------------------- */

  // Poll cadence while something is running. The job record is a single
  // option read on the server, so this is cheap; it drops to a much slower
  // idle cadence when nothing is going on.
  const JOB_POLL_MS = 1500;
  const JOB_IDLE_POLL_MS = 15000;

  const $toolCards = $(".isxs-tool");
  const cardFor = (tool) => $('.isxs-tool[data-tool="' + tool + '"]');

  let jobs = {};
  // Whether the site can drive its own runs. False means the tab has to
  // keep ticking them (see pollJobs) — the run still survives a reload,
  // it just doesn't progress while the page is closed.
  let loopbackOk = true;
  let jobPollTimer = null;
  let jobRequestInFlight = false;
  let pollFailures = 0;

  const anyJobRunning = () =>
    Object.keys(jobs).some((tool) => jobs[tool].state === "running");

  const phaseLabel = (job) => {
    if (job.phase === "counting") {
      return cfg.i18n.counting;
    }
    if (job.phase === "rewriting") {
      return cfg.i18n.rewriting;
    }
    return "";
  };

  /**
   * Paint one tool card from its job record. Called for every card on
   * every poll, so it has to be a pure function of the record — no
   * accumulated client-side state to drift out of sync.
   */
  const renderToolCard = ($card, job) => {
    const $btn = $card.find(".isxs-tool-run");
    const $stopBtn = $card.find(".isxs-tool-stop");
    const $cancelBtn = $card.find(".isxs-tool-cancel");
    const $progress = $card.find(".isxs-tool-progress");
    const $fill = $progress.find(".isxs-progress-fill");
    const $percent = $card.find(".isxs-tool-percent");
    const $count = $card.find(".isxs-tool-count");
    const $eta = $card.find(".isxs-tool-eta");
    const $errors = $card.find(".isxs-tool-errors");
    const originalLabel = $card.data("originalRunLabel");
    // The idle button keeps its per-tool style (e.g. the delete tool's
    // red "ลบไฟล์ทั้งหมดออกจาก bucket"); while a job is RUNNING it is
    // forced to the same blue primary look as the Offload card, then the
    // original style is restored once the run settles.
    const originalRunClass =
      $card.data("originalRunBtnClass") || $btn.attr("class");
    $card.data("originalRunBtnClass", originalRunClass);

    if (!job || job.state === "cancelled") {
      $btn.attr("class", originalRunClass);
      $btn.removeAttr("hidden").prop("disabled", false).text(originalLabel);
      $stopBtn.attr("hidden", true);
      $cancelBtn.attr("hidden", true);
      $progress.attr("hidden", true);
      $eta.text("");
      $errors.attr("hidden", true).empty();
      return;
    }

    const running = job.state === "running";
    const done = job.state === "done";
    const resumable = job.state === "paused" || job.state === "error";
    // A stop the server has accepted but the runner has not reached yet —
    // it finishes the batch in flight first. Kept out of the record itself
    // so it can never be overwritten by progress, so it arrives as its own
    // field (see ISXM_Background::status_payload).
    const pausing = job.signal === "pause";
    const cancelling = job.signal === "cancel";

    // While a run is going the only useful actions are the two stops, so the
    // start button steps aside entirely rather than sitting there greyed out
    // saying "กำลังทำงาน…" — the progress bar right below already says that,
    // and three buttons in a row read as a choice the user doesn't have.
    // The card then matches WP Offload Media: [หยุด] [ยกเลิก] while running,
    // [ยกเลิก] [ทำต่อ] once stopped, [เริ่ม …] when idle.
    $btn.attr("class", originalRunClass);
    $btn
      .attr("hidden", running)
      .prop("disabled", false)
      .text(resumable ? cfg.i18n.resume : originalLabel);

    // Both stops are offered side by side whenever there is something to
    // stop — the same pairing WP Offload Media uses. Cancel used to appear
    // only once a run was already paused, which meant abandoning a run took
    // two round trips through a state the user never wanted to be in.
    $stopBtn
      .attr("hidden", !running)
      .prop("disabled", pausing || cancelling)
      .text(pausing ? cfg.i18n.stopping : cfg.i18n.stop);
    $cancelBtn
      .attr("hidden", !(running || resumable))
      .prop("disabled", cancelling)
      .text(cancelling ? cfg.i18n.cancelling : cfg.i18n.cancel);
    $progress.removeAttr("hidden");

    // A denominator of 0 means "not known yet" (migrate, before its
    // counting phase finishes) — show an indeterminate bar rather than a
    // percentage computed from a number nobody has yet.
    if (job.total > 0) {
      $fill.css("width", job.percent + "%");
      $percent.text(job.percent + "%");
    } else {
      $fill.css("width", running ? "5%" : "0%");
      $percent.text("");
    }

    const totalLabel =
      (!job.total_complete && job.total > 0 ? "~" : "") +
      job.total.toLocaleString();
    const statusLabel = done
      ? cfg.i18n.done
      : job.stalled
        ? cfg.i18n.stalled
        : job.state === "paused"
          ? cfg.i18n.stopped
          : job.state === "error"
            ? cfg.i18n.error
            : "";

    $count.text(
      (job.total > 0
        ? job.processed.toLocaleString() + "/" + totalLabel
        : job.processed.toLocaleString()) +
        " รายการ" +
        (statusLabel ? " — " + statusLabel : ""),
    );

    const elapsedStr =
      job.elapsed_seconds > 0
        ? formatDuration(job.elapsed_seconds * 1000)
        : "";

    if (running) {
      const phase = phaseLabel(job);
      if (phase) {
        $eta.text(
          phase + (elapsedStr ? " (ใช้เวลาไปแล้ว " + elapsedStr + ")" : ""),
        );
      } else {
        const parts = [];
        if (elapsedStr) {
          parts.push("ใช้เวลาไปแล้ว " + elapsedStr);
        }
        if (job.eta_seconds > 0) {
          parts.push(
            "เหลืออีกประมาณ " +
              formatDuration(job.eta_seconds * 1000) +
              " · เสร็จประมาณ " +
              formatFinishTime(job.eta_seconds),
          );
        } else if (!loopbackOk) {
          parts.push(cfg.i18n.keepTabOpen);
        }
        $eta.text(parts.join(" · "));
      }
    } else if (done) {
      $eta.text(
        elapsedStr ? "เสร็จสิ้น — ใช้เวลาทั้งหมด " + elapsedStr : "",
      );
    } else if (resumable) {
      $eta.text(
        elapsedStr ? "หยุดพัก — ใช้เวลาไปแล้ว " + elapsedStr : "",
      );
    } else if (job.state === "cancelled") {
      $eta.text(
        elapsedStr ? "ยกเลิกแล้ว — ใช้เวลาไป " + elapsedStr : "",
      );
    } else {
      $eta.text("");
    }

    // Errors are re-rendered wholesale from the record rather than
    // appended as they arrive: the record is the full (capped) list, so
    // this can't drift or duplicate across polls. Copied, not aliased —
    // pushing the run message onto job.errors itself would append one more
    // copy of it on every single poll.
    $errors.empty();
    const lines = (job.errors || []).slice();
    if (job.message) {
      lines.push(job.message);
    }
    if (lines.length) {
      lines.forEach((line) => $errors.append($("<li>").text(line)));
      if (job.error_count > lines.length) {
        $errors.append(
          $("<li>").text(
            "…และอีก " +
              (job.error_count - lines.length).toLocaleString() +
              " รายการ",
          ),
        );
      }
      if (job.error_count > 0 && cfg.mediaFailedUrl) {
        $errors.append(
          $('<li class="isxs-tool-errors-summary">')
            .text("ไม่ผ่าน " + job.error_count.toLocaleString() + " รายการ — ")
            .append(
              $("<a>")
                .attr("href", cfg.mediaFailedUrl)
                .text("ดูรายการในหน้าสื่อ"),
            ),
        );
      }
      $errors.removeAttr("hidden");
    } else {
      $errors.attr("hidden", true);
    }
  };

  /**
   * Fold one server response into the UI. Every job endpoint answers with
   * the same payload, so start/pause/cancel/poll all land here.
   */
  // Sync card's "last checked" line — updated from every job-status poll
  // (not just its own refresh button) so it stays current without extra
  // requests. Queried by selector rather than cached because the sync card
  // may not exist in the DOM yet when this file first runs.
  const updateSyncStatusLine = (lastRun, stale) => {
    const $text = $(".isxs-sync-status-text");
    if (!$text.length) {
      return;
    }
    let text;
    if (!lastRun) {
      text = "ยังไม่เคยตรวจสอบกับ bucket จริง";
    } else {
      const days = Math.floor((Date.now() / 1000 - lastRun) / 86400);
      text =
        days <= 0
          ? "ตรวจล่าสุดวันนี้"
          : "ตรวจล่าสุดเมื่อ " + days + " วันที่แล้ว";
      if (stale) {
        text += " — นานแล้ว ควรตรวจอีกครั้ง";
      }
    }
    $text.text(text);
    $(".isxs-sync-status").toggleClass("is-stale", !!stale);
  };

  const applyJobPayload = (res) => {
    if (!res || !res.success || !res.data) {
      return false;
    }
    const data = res.data;

    // Every response re-mints the nonce, so a page left open overnight (or
    // a run that outlives the 24h nonce lifetime) keeps working instead of
    // failing with an unexplained error on the next click.
    if (data.nonce) {
      cfg.nonce = data.nonce;
    }
    if (typeof data.loopback !== "undefined") {
      loopbackOk = !!data.loopback;
    }
    if (typeof data.sync_last_run !== "undefined") {
      updateSyncStatusLine(data.sync_last_run, data.sync_stale);
    }
    // Jobs first: applyStats() below decides whether the retry card is
    // visible, and that decision reads the running state from here.
    jobs = data.jobs || {};

    // Keep the header's quick-offload button honest while a run is going.
    const offJob = jobs.offload;
    const offRunning = !!(offJob && offJob.state === "running");
    $(".isxs-status-offload-btn")
      .prop("disabled", offRunning)
      .text(offRunning ? cfg.i18n.working : "Offload Remaining");

    if (data.stats) {
      cfg.stats = data.stats;
      applyStats(data.stats);
    }

    $toolCards.each((index, el) => {
      const $card = $(el);
      renderToolCard($card, jobs[$card.data("tool")]);
    });

    // Only one job runs at a time. While one card is running, disable the
    // other cards' start buttons with an explanatory tooltip — a refused
    // start would otherwise just flash an error toast and leave the card
    // looking like nothing happened (no progress row ever appears).
    const runningTool = Object.keys(jobs).find(
      (t) => jobs[t] && jobs[t].state === "running",
    );
    $(".isxs-tool .isxs-tool-run").each(function () {
      const $run = $(this);
      const $card = $(this).closest(".isxs-tool");
      if (runningTool && $card.data("tool") !== runningTool) {
        $run
          .prop("disabled", true)
          .attr(
            "title",
            "มีงานอื่นกำลังทำงานอยู่ — หยุดงานนั้นก่อนเริ่มงานใหม่",
          );
      } else {
        $run.removeAttr("title");
      }
    });
    return true;
  };

  const scheduleJobPoll = (delay) => {
    clearTimeout(jobPollTimer);
    jobPollTimer = setTimeout(
      pollJobs,
      typeof delay === "number" ? delay : anyJobRunning() ? JOB_POLL_MS : JOB_IDLE_POLL_MS,
    );
  };

  /**
   * One poll. In loopback mode this only reads state; without loopback the
   * very same call also drives one slice of work, which is what keeps the
   * fallback mode honest — one code path, two ways of being driven.
   */
  const pollJobs = () => {
    if (jobRequestInFlight) {
      scheduleJobPoll();
      return;
    }
    // Nothing to watch and nothing to drive — stay quiet until a click.
    if (!anyJobRunning() && !Object.keys(jobs).length) {
      scheduleJobPoll(JOB_IDLE_POLL_MS);
      return;
    }

    const running = anyJobRunning();
    // A running job whose driver went quiet (loopback killed by the host,
    // PHP fatal) is nudged straight from here rather than waiting for the
    // cron healthcheck to notice.
    const needsDriving =
      running && (!loopbackOk || Object.keys(jobs).some((t) => jobs[t].stalled));

    jobRequestInFlight = true;
    $.post(cfg.ajaxUrl, {
      action: needsDriving ? "isxs_job_tick" : "isxs_job_status",
      nonce: cfg.nonce,
    })
      .done((res) => {
        pollFailures = 0;
        applyJobPayload(res);
      })
      .fail((xhr) => {
        pollFailures++;
        if (xhr && (xhr.status === 403 || xhr.status === 401)) {
          showToast(cfg.i18n.sessionExpired, true);
        }
      })
      .always(() => {
        jobRequestInFlight = false;
        // Back off on repeated transport failures instead of hammering a
        // server that is already unhappy. The run itself is unaffected —
        // it lives on the server, not in this loop.
        scheduleJobPoll(
          pollFailures > 0 ? Math.min(2000 * pollFailures, 30000) : undefined,
        );
      });
  };

  /**
   * Shared sender for the job actions. Renders the response immediately so
   * a click feels instant instead of waiting for the next poll tick.
   */
  const jobAction = (action, tool, extra) => {
    $.post(
      cfg.ajaxUrl,
      $.extend({ action: action, nonce: cfg.nonce, tool: tool }, extra || {}),
    )
      .done((res) => {
        if (res && res.success) {
          applyJobPayload(res);
          scheduleJobPoll(500);
        } else {
          showToast((res && res.data && res.data.message) || cfg.i18n.error, true);
          // A refused start leaves the card mid-click — re-read the real
          // state rather than leaving a disabled button behind.
          scheduleJobPoll(0);
        }
      })
      .fail((xhr) => {
        showToast(
          xhr && (xhr.status === 403 || xhr.status === 401)
            ? cfg.i18n.sessionExpired
            : cfg.i18n.error,
          true,
        );
        scheduleJobPoll(0);
      });
  };

  // The retry card is meaningless with nothing to retry, so it ships hidden
  // and appears/disappears as the failure count changes — including right
  // after a run, without a page reload.
  const syncRetryCard = (stats) => {
    if (!stats || typeof stats.failed === "undefined") {
      return;
    }
    const $card = cardFor("retry_failed");
    if (!$card.length) {
      return;
    }
    const job = jobs.retry_failed;
    // Never yank the card out from under a run that's still going.
    if (stats.failed > 0 || (job && job.state === "running")) {
      $card.removeAttr("hidden");
    } else {
      $card.attr("hidden", true);
    }
  };

  $(".isxs-tool .isxs-tool-run").on("click", (e) => {
    const $card = $(e.currentTarget).closest(".isxs-tool");
    const tool = $card.data("tool");
    const job = jobs[tool];
    const resume = !!(job && (job.state === "paused" || job.state === "error"));

    if (tool === "remove" && !resume && !window.confirm(cfg.i18n.confirmRemove)) {
      return;
    }

    // Optimistic: swap to the running layout now — start button away, stops
    // in its place — and let the response (or the next poll) confirm it. A
    // refused start repaints from the real record, so nothing sticks.
    const $runBtn = $(e.currentTarget);
    if (!$card.data("originalRunBtnClass")) {
      $card.data("originalRunBtnClass", $runBtn.attr("class"));
    }
    $runBtn.attr("hidden", true);
    $card
      .find(".isxs-tool-stop")
      .removeAttr("hidden")
      .prop("disabled", false)
      .text(cfg.i18n.stop);
    $card
      .find(".isxs-tool-cancel")
      .removeAttr("hidden")
      .prop("disabled", false)
      .text(cfg.i18n.cancel);
    // Show the progress row immediately so the click visibly does
    // something — the server response (or the next poll) fills it in.
    $card.find(".isxs-tool-progress").removeAttr("hidden");
    $card.find(".isxs-tool-eta").text("");
    jobAction("isxs_job_start", tool, resume ? { resume: 1 } : {});
  });

  $(".isxs-tool .isxs-tool-stop").on("click", (e) => {
    const $card = $(e.currentTarget).closest(".isxs-tool");
    $(e.currentTarget).prop("disabled", true).text(cfg.i18n.stopping);
    // The batch already in flight finishes server-side (there is no
    // per-item cancel token) and its work is counted before the job
    // settles — nothing is lost and nothing is redone on resume.
    jobAction("isxs_job_pause", $card.data("tool"));
  });

  // Discards the run's cursor. The work already done is never touched — a
  // later fresh start still skips completed items on its own, it just
  // rescans from the beginning to find them. Available while a run is
  // going as well as once it is stopped, so walking away from a run takes
  // one click rather than "หยุด" and then "ยกเลิก".
  $(".isxs-tool .isxs-tool-cancel").on("click", (e) => {
    const $card = $(e.currentTarget).closest(".isxs-tool");
    const job = jobs[$card.data("tool")];
    // Only worth asking while there is a cursor to lose. A run that never
    // got going has nothing to throw away.
    if (job && job.processed > 0 && !window.confirm(cfg.i18n.confirmCancel)) {
      return;
    }
    $(e.currentTarget).prop("disabled", true).text(cfg.i18n.cancelling);
    jobAction("isxs_job_cancel", $card.data("tool"));
  });

  /* ------------------------------------------------------------------
   * Reset all tools' UI + re-sync connection status and stats — used both
   * by the manual "รีเซตทั้งหมดใน Tools" button and automatically after a
   * settings change that switches the destination bucket, since a resume
   * cursor aimed at a bucket that is no longer configured is meaningless.
   * Server-side work is never touched; this only forgets "where we
   * stopped".
   * ---------------------------------------------------------------- */

  function resetToolsUI() {
    // One request, not one per card: the server stops anything running and
    // drops every cursor together, so there is no window where half the
    // tools have been reset and half have not.
    $.post(cfg.ajaxUrl, { action: "isxs_job_reset", nonce: cfg.nonce })
      .done((res) => {
        applyJobPayload(res);
        scheduleJobPoll(JOB_IDLE_POLL_MS);
      })
      .fail(() => {
        showToast(cfg.i18n.error, true);
      });
  }

  /**
   * The wall-clock time a run is expected to land on, from its remaining
   * seconds. Rendered in the viewer's own timezone (the server's ETA is a
   * duration, deliberately, so it can't disagree with the reader's clock).
   *
   * A run that finishes past midnight gets the day spelled out — "เสร็จ
   * ประมาณ 02:15" on its own would read as "in a few minutes" at 11pm.
   */
  const formatFinishTime = (etaSeconds) => {
    const finish = new Date(Date.now() + etaSeconds * 1000);
    const time = finish.toLocaleTimeString("th-TH", {
      hour: "2-digit",
      minute: "2-digit",
    });

    const startOfToday = new Date();
    startOfToday.setHours(0, 0, 0, 0);
    const dayDiff = Math.floor((finish - startOfToday) / 86400000);

    if (dayDiff === 0) {
      return time + " น.";
    }
    if (dayDiff === 1) {
      return "พรุ่งนี้ " + time + " น.";
    }
    return (
      finish.toLocaleDateString("th-TH", { day: "numeric", month: "short" }) +
      " " +
      time +
      " น."
    );
  };

  const formatDuration = (ms) => {
    if (!isFinite(ms) || ms <= 0) {
      return "0 วินาที";
    }
    const totalSec = Math.round(ms / 1000);
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    if (h > 0) {
      return h + " ชม. " + m + " นาที";
    }
    if (m > 0) {
      return m + " นาที " + s + " วินาที";
    }
    return s + " วินาที";
  };



  /* ------------------------------------------------------------------
   * Sync/Verify tool — scan the REAL bucket, diff against tracking meta
   * (the Overview numbers and every tool's skip decision read postmeta,
   * so files deleted out-of-band leave stale records behind; this tool
   * finds them and, on apply, drops the records so Offload/Migrate treat
   * those attachments as pending again).
   * ---------------------------------------------------------------- */

  const $syncCard = $(".isxs-sync-card");
  if ($syncCard.length) {
    const $syncRun = $syncCard.find(".isxs-sync-run");
    const $syncApply = $syncCard.find(".isxs-sync-apply");
    const $syncProgress = $syncCard.find(".isxs-tool-progress");
    const $syncFill = $syncProgress.find(".isxs-progress-fill");
    const $syncCount = $syncCard.find(".isxs-tool-count");
    const $syncEta = $syncCard.find(".isxs-tool-eta");
    const $syncErrors = $syncCard.find(".isxs-tool-errors");
    const $syncResult = $syncCard.find(".isxs-sync-result");
    const $syncSummary = $syncCard.find(".isxs-sync-summary");
    const $syncSample = $syncCard.find(".isxs-sync-sample");
    const $syncInlineOk = $syncCard.find(".isxs-sync-inline-ok");

    // Lowercase alphanumeric so it survives the server's sanitize_key().
    // The scan state lives server-side keyed by this, and the apply step
    // reuses the same id to find what the scan found.
    const syncRunId = () =>
      "s" + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

    let syncBusy = false;

    const $syncOrphanRun = $syncCard.find(".isxs-sync-orphan-run");
    const $syncOrphanActions = $syncCard.find(".isxs-sync-orphan-actions");

    const renderSyncResult = (r) => {
      const stale = (r.stale_ids || []).length;
      const partial = (r.partial_ids || []).length;
      const dataLoss = (r.data_loss_ids || []).length;
      const orphan = r.orphan || 0;
      const outside = r.outside_prefix || 0;
      $syncSummary.empty();
      $syncSample.empty();

      // Everything matches — one green line right next to the stats, no
      // result box below.
      if (stale === 0 && partial === 0 && orphan === 0 && outside === 0) {
        $syncInlineOk.removeAttr("hidden");
        $syncResult.attr("hidden", true);
        $syncApply.attr("hidden", true);
        $syncOrphanRun.attr("hidden", true);
        $syncOrphanActions.attr("hidden", true);
        return;
      }
      $syncInlineOk.attr("hidden", true);

      // One short line per finding — the card lives on the Overview, so
      // the result has to stay compact; the buttons carry the next step.
      if (stale > 0) {
        $syncSummary.append(
          $("<p class='isxs-sync-stale'>").text(
            "พบ meta ค้าง " + stale.toLocaleString() + " รายการ",
          ),
        );
      }
      if (dataLoss > 0) {
        $syncSummary.append(
          $("<p class='isxs-sync-stale'>").text(
            "ในนั้น " +
              dataLoss.toLocaleString() +
              " รายการไฟล์หายทั้งสองที่ (ดู badge แดงในหน้าสื่อ)",
          ),
        );
      }
      if (partial > 0) {
        $syncSummary.append(
          $("<p class='isxs-sync-orphan'>").text(
            "ขึ้นไม่ครบทุกขนาด " + partial.toLocaleString() + " รายการ",
          ),
        );
      }
      if (orphan > 0) {
        $syncSummary.append(
          $("<p class='isxs-sync-orphan'>").text(
            "object ไม่มี media ตรงกัน " + orphan.toLocaleString() + " ไฟล์",
          ),
        );
      }
      if (outside > 0) {
        $syncSummary.append(
          $("<p class='isxs-sync-orphan'>").text(
            "object นอก prefix " + outside.toLocaleString() + " ไฟล์ (ไม่แตะ)",
          ),
        );
      }

      const sample = r.orphan_sample || [];
      if (sample.length) {
        const $ul = $("<ul>");
        sample.slice(0, 5).forEach((key) => {
          $ul.append($("<li>").text(key));
        });
        $syncSample.append($ul);
      }

      $syncResult.removeAttr("hidden");
      // Same attr-based show/hide convention as the rest of the file —
      // jQuery's .toggle() wouldn't lift the `hidden` attribute itself.
      if (stale > 0) {
        $syncApply.removeAttr("hidden");
      } else {
        $syncApply.attr("hidden", true);
      }
      $syncApply.text("ล้าง meta ค้าง (" + stale.toLocaleString() + ")");

      if (orphan > 0) {
        $syncOrphanRun.removeAttr("hidden").text("ลบ orphan objects (" + orphan.toLocaleString() + ")");
        $syncOrphanActions.removeAttr("hidden");
      } else {
        $syncOrphanRun.attr("hidden", true);
        $syncOrphanActions.attr("hidden", true);
      }
    };

    const syncFinish = (ok, result, message) => {
      syncBusy = false;
      $syncRun.prop("disabled", false).text("ซิงก์ให้ตรงกับ bucket");
      $syncEta.text("");
      if (ok) {
        $syncFill.css("width", "100%");
        renderSyncResult(result || {});
        // The server just marked this scan as the new last-run — reflect
        // it immediately instead of waiting for the next poll tick.
        updateSyncStatusLine(Math.floor(Date.now() / 1000), false);
      } else {
        $syncFill.css("width", "5%");
        if (message) {
          $syncErrors.removeAttr("hidden").append($("<li>").text(message));
        }
      }
    };

    $syncRun.on("click", () => {
      if (syncBusy) {
        return;
      }
      syncBusy = true;
      const runId = syncRunId();
      $syncCard.data("syncRunId", runId);

      $syncRun.prop("disabled", true).text("กำลังซิงก์…");
      $syncApply.attr("hidden", true);
      $syncResult.attr("hidden", true);
      $syncInlineOk.attr("hidden", true);
      $syncErrors.attr("hidden", true).empty();
      $syncEta.text("กำลังซิงก์กับ bucket จริง…");
      $syncProgress.removeAttr("hidden");
      $syncFill.css("width", "5%");
      $syncCount.text("0 รายการ");

      // Each batch response reports only what THIS request processed —
      // accumulate for a running total across the whole scan.
      let scanProcessed = 0;
      const step = () => {
        $.post(cfg.ajaxUrl, {
          action: "isxs_sync_scan",
          nonce: cfg.nonce,
          run_id: runId,
        })
          .done((res) => {
            if (!res || !res.success || !res.data) {
              syncFinish(
                false,
                null,
                (res && res.data && res.data.message) || cfg.i18n.error,
              );
              return;
            }
            // Long scans can outlive the nonce the page was rendered with.
            if (res.data.nonce) {
              cfg.nonce = res.data.nonce;
            }
            scanProcessed += res.data.processed || 0;
            $syncCount.text(scanProcessed.toLocaleString() + " รายการ");
            (res.data.errors || []).forEach((err) => {
              $syncErrors.removeAttr("hidden").append($("<li>").text(err));
            });
            if (res.data.done) {
              syncFinish(true, res.data.result || {});
            } else if (res.data.stalled) {
              syncFinish(false, null);
            } else {
              step();
            }
          })
          .fail(() => {
            syncFinish(false, null, cfg.i18n.connectionLost);
          });
      };
      step();
    });

    $syncApply.on("click", () => {
      if (syncBusy) {
        return;
      }
      syncBusy = true;
      $syncApply.prop("disabled", true).text("กำลังล้าง…");

      $.post(cfg.ajaxUrl, {
        action: "isxs_sync_apply",
        nonce: cfg.nonce,
        run_id: $syncCard.data("syncRunId") || "",
      })
        .done((res) => {
          if (!res || !res.success || !res.data) {
            syncFinish(
              false,
              null,
              (res && res.data && res.data.message) || cfg.i18n.error,
            );
            return;
          }
          if (res.data.nonce) {
            cfg.nonce = res.data.nonce;
          }
          if (res.data.stats) {
            cfg.stats = res.data.stats;
            applyStats(res.data.stats);
          }
          const cleaned = res.data.processed || 0;
          const dataLoss = res.data.data_loss || 0;
          const $msg = $("<p class='isxs-sync-stale'>").text(
            "ล้าง meta ค้างเรียบร้อย " +
              cleaned.toLocaleString() +
              " รายการ — กด “เริ่ม Offload” เพื่ออัปโหลดขึ้นใหม่",
          );
          $syncSummary.empty().append($msg);
          if (dataLoss > 0) {
            $syncSummary.append(
              $("<p class='isxs-sync-stale'>").text(
                dataLoss.toLocaleString() +
                  " รายการไฟล์หายทั้งสองที่ (ดู badge แดงในหน้าสื่อ)",
              ),
            );
          }
          $syncResult.removeAttr("hidden");
          $syncApply.attr("hidden", true);
          syncBusy = false;
          $syncApply.prop("disabled", false).text("ล้าง meta ค้าง");
        })
        .fail(() => {
          syncFinish(false, null, cfg.i18n.connectionLost);
        });
    });

    // Orphan cleanup — a second, destructive pass that re-lists the bucket
    // and deletes objects under the current prefix that no attachment
    // references. Confirmed before starting, batched like the scan.
    const orphanFinishError = (message) => {
      syncBusy = false;
      $syncOrphanRun.prop("disabled", false).text("ลบ orphan objects");
      syncFinish(false, null, message);
    };

    $syncOrphanRun.on("click", () => {
      if (syncBusy) {
        return;
      }
      if (
        !window.confirm(
          "ลบ orphan objects ทั้งหมดออกจาก bucket?\n\nจะลบเฉพาะ object ใน prefix ปัจจุบันที่ไม่มี media ใน WordPress ตรงกันเท่านั้น — object นอก prefix (เช่นของเว็บอื่น/backup) จะไม่ถูกแตะ แต่ควรมี backup ก่อนเสมอ",
        )
      ) {
        return;
      }
      syncBusy = true;
      const runId = syncRunId();

      $syncOrphanRun.prop("disabled", true).text("กำลังลบ…");
      $syncApply.attr("hidden", true);
      $syncErrors.attr("hidden", true).empty();
      $syncEta.text("กำลังลิสต์และลบ orphan…");
      $syncProgress.removeAttr("hidden");
      $syncFill.css("width", "5%");
      $syncCount.text("0 รายการ");

      let orphanProcessed = 0;
      const step = () => {
        $.post(cfg.ajaxUrl, {
          action: "isxs_sync_orphan_cleanup",
          nonce: cfg.nonce,
          run_id: runId,
        })
          .done((res) => {
            if (!res || !res.success || !res.data) {
              orphanFinishError(
                (res && res.data && res.data.message) || cfg.i18n.error,
              );
              return;
            }
            if (res.data.nonce) {
              cfg.nonce = res.data.nonce;
            }
            orphanProcessed += res.data.processed || 0;
            $syncCount.text(orphanProcessed.toLocaleString() + " รายการ");
            (res.data.errors || []).forEach((err) => {
              $syncErrors.removeAttr("hidden").append($("<li>").text(err));
            });
            if (res.data.done) {
              const deleted = res.data.deleted || 0;
              syncBusy = false;
              $syncFill.css("width", "100%");
              $syncEta.text("");
              $syncOrphanRun.prop("disabled", false).text("ลบ orphan objects");
              $syncSummary
                .empty()
                .append(
                  $("<p class='isxs-sync-stale'>").text(
                    "ลบ orphan objects เรียบร้อย " +
                      deleted.toLocaleString() +
                      " ไฟล์ — bucket สะอาดแล้ว",
                  ),
                );
              $syncResult.removeAttr("hidden");
              $syncOrphanActions.attr("hidden", true);
              if (res.data.stats) {
                cfg.stats = res.data.stats;
              }
            } else if (res.data.stalled) {
              orphanFinishError(null);
            } else {
              step();
            }
          })
          .fail(() => {
            orphanFinishError(cfg.i18n.connectionLost);
          });
      };
      step();
    });
  }

  /* ------------------------------------------------------------------
   * Offload Status widget (header) — dropdown toggle, refresh, quick
   * offload; and the connection editor reveal (แก้ไข / ปิด buttons).
   * ---------------------------------------------------------------- */

  const $statusWidget = $(".isxs-status-widget");
  const $statusPanel = $("#isxs-status-panel");

  if ($statusWidget.length) {
    $statusWidget.on("click", (e) => {
      e.stopPropagation();
      const open = $statusPanel.is(":visible");
      $statusPanel.attr("hidden", open);
      $statusWidget.attr("aria-expanded", open ? "false" : "true");
    });
    // Click anywhere else closes the panel.
    $(document).on("click", (e) => {
      if (!$(e.target).closest(".isxs-status-wrap").length) {
        $statusPanel.attr("hidden", true);
        $statusWidget.attr("aria-expanded", "false");
      }
    });
    // Refresh re-reads the stats directly (independent of the poll loop)
    // while the panel stays open — with a spinning icon so the click
    // visibly does something instead of silently closing.
    $(".isxs-status-refresh").on("click", () => {
      const $btn = $(".isxs-status-refresh");
      if ($btn.hasClass("is-refreshing")) {
        return;
      }
      $btn.addClass("is-refreshing");
      $.post(cfg.ajaxUrl, { action: "isxs_job_status", nonce: cfg.nonce })
        .done((res) => {
          if (res && res.success) {
            applyJobPayload(res);
            scheduleJobPoll(500);
          }
        })
        .fail(() => {
          showToast(cfg.i18n.error, true);
        })
        .always(() => {
          $btn.removeClass("is-refreshing");
        });
    });
    // Quick offload straight from the dropdown — same server-side job the
    // Tools tab's card starts.
    $(".isxs-status-offload-btn").on("click", (e) => {
      $(e.currentTarget).prop("disabled", true);
      jobAction("isxs_job_start", "offload", {});
    });
  }

  // Custom provider picker on the Storage card — a button + listbox
  // (native <select> can't render the provider logos). Picking an option
  // goes through the same path as the picker cards on the connections tab.
  const $providerPicker = $("#isxs-dest-provider-picker");
  const $providerMenu = $("#isxs-dest-provider-menu");

  const toggleProviderMenu = (open) => {
    if (!open) {
      $providerMenu.attr("hidden", true);
      $providerPicker.removeClass("is-open");
      $providerPicker.find(".isxs-picker-trigger").attr("aria-expanded", "false");
      return;
    }
    $providerMenu.removeAttr("hidden");
    $providerPicker.addClass("is-open");
    $providerPicker.find(".isxs-picker-trigger").attr("aria-expanded", "true");
  };

  $providerPicker.find(".isxs-picker-trigger").on("click", (e) => {
    e.stopPropagation();
    toggleProviderMenu($providerMenu.is("[hidden]"));
  });

  $providerMenu.on("click", (e) => {
    const $opt = $(e.target).closest(".isxs-picker-option");
    if (!$opt.length || $opt.hasClass("is-disabled")) {
      return;
    }
    toggleProviderMenu(false);
    selectDestinationProvider($opt.data("provider"));
  });

  // Close on outside click and on Escape (with focus restored to the trigger).
  $(document).on("click", (e) => {
    if ($providerPicker.length && !$providerPicker[0].contains(e.target)) {
      toggleProviderMenu(false);
    }
  });
  $(document).on("keydown", (e) => {
    if (e.key === "Escape" && !$providerMenu.is("[hidden]")) {
      toggleProviderMenu(false);
      $providerPicker.find(".isxs-picker-trigger").trigger("focus");
    }
  });

  /* ------------------------------------------------------------------
   * Init
   * ---------------------------------------------------------------- */

  syncPrefixField();
  updateUrlPreview();
  applyStats(cfg.stats);

  // Captured once so a card can always be put back to its idle label —
  // reading the button text later would pick up "ทำต่อ"/"กำลังทำงาน…"
  // instead of the original.
  $toolCards.each((index, el) => {
    const $t = $(el);
    $t.data("originalRunLabel", $t.find(".isxs-tool-run").text());
  });

  // First paint comes from the server, not from anything the browser
  // remembered: a run started in another tab (or before this page was even
  // opened) shows up correctly, and a stale local guess can never win over
  // the real record.
  if ($toolCards.length) {
    $.post(cfg.ajaxUrl, { action: "isxs_job_status", nonce: cfg.nonce })
      .done((res) => {
        applyJobPayload(res);
        scheduleJobPoll(anyJobRunning() ? 0 : JOB_IDLE_POLL_MS);
      })
      .fail(() => {
        // Even a failed first read must leave the cards in their idle
        // state rather than blank, and keep polling — the server is the
        // only place that knows what is going on.
        $toolCards.each((index, el) => renderToolCard($(el), null));
        scheduleJobPoll(5000);
      });
  }

})(jQuery);
