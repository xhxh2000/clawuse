/* ==========================================
   App Common JS - PWA-like UI Framework
   ========================================== */

(function() {
  'use strict';

  // ---- Tab Bar Active State ----
  function initTabBar() {
    var path = location.pathname;
    var currentPage = path.split('/').pop() || 'index.html';
    var tabItems = document.querySelectorAll('.app-tabbar .tab-item');
    tabItems.forEach(function(item) {
      var href = item.getAttribute('href');
      if (href === currentPage) {
        item.classList.add('active');
      } else {
        item.classList.remove('active');
      }
    });
  }

  // ---- User Sheet ----
  function openUserSheet() {
    var overlay = document.getElementById('sheetOverlay');
    var sheet = document.getElementById('userSheet');
    if (overlay && sheet) {
      overlay.classList.add('show');
      sheet.classList.add('show');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeUserSheet() {
    var overlay = document.getElementById('sheetOverlay');
    var sheet = document.getElementById('userSheet');
    if (overlay && sheet) {
      overlay.classList.remove('show');
      sheet.classList.remove('show');
      document.body.style.overflow = '';
    }
  }

  // ---- Render User Sheet Content ----
  function renderUserSheet() {
    var sheet = document.getElementById('userSheet');
    if (!sheet) return;

    var username = localStorage.getItem('username') || '未登录';
    var role = localStorage.getItem('role');
    var gold = localStorage.getItem('gold') || '0';
    var isAdmin = role === 'admin';

    var html = '<div class="user-sheet-header">' +
      '<div class="user-sheet-avatar">' + (isAdmin ? '👑' : '👤') + '</div>' +
      '<div class="user-sheet-info">' +
        '<h3>' + username + (isAdmin ? ' <span style="font-size:12px;color:#ffd700;">管理员</span>' : '') + '</h3>' +
      '</div>' +
    '</div>' +
    '<div class="user-sheet-menu">';

    if (isAdmin) {
      html +=
        '<a href="admin_card_info.html" class="user-sheet-item"><span class="sheet-icon">🎴</span>卡牌管理</a>' +
        '<a href="admin_card_draw.html" class="user-sheet-item"><span class="sheet-icon">🎯</span>抽卡管理</a>' +
        '<a href="admin_users.html" class="user-sheet-item"><span class="sheet-icon">👥</span>用户管理</a>' +
        '<a href="admin_sys.html" class="user-sheet-item"><span class="sheet-icon">⚙️</span>系统配置</a>' +
        '<div class="user-sheet-divider"></div>';
    }

    html +=
      '<a href="#" class="user-sheet-item danger" onclick="AppCommon.logout();return false;"><span class="sheet-icon">🚪</span>退出登录</a>' +
      '</div>';

    sheet.innerHTML = html;
  }

  // ---- Logout ----
  function logout() {
    localStorage.clear();
    window.location.href = 'login.html';
  }

  // ---- Init ----
  function init() {
    initTabBar();
    renderUserSheet();

    // Close sheet on overlay click
    var overlay = document.getElementById('sheetOverlay');
    if (overlay) {
      overlay.addEventListener('click', closeUserSheet);
    }

    // Add body class for tab bar padding
    if (document.querySelector('.app-tabbar')) {
      document.body.classList.add('has-tabbar');
    }
  }

  // ---- Expose to global ----
  window.AppCommon = {
    openUserSheet: openUserSheet,
    closeUserSheet: closeUserSheet,
    logout: logout
  };

  // Auto-init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
