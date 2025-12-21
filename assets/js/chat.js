(function () {
  const API_BASE = window.CHAT_API_BASE || '/api/chat';
  const PRESENCE_INTERVAL = 30000;
  const POLLING_INTERVAL = 30000; // زيادة من 12 ثانية إلى 30 ثانية لتقليل الضغط على السيرفر

  const selectors = {
    app: '[data-chat-app]',
    messageList: '[data-chat-messages]',
    userList: '[data-chat-users]',
    sendButton: '[data-chat-send]',
    input: '[data-chat-input]',
    toast: '[data-chat-toast]',
    replyBar: '[data-chat-reply]',
    replyDismiss: '[data-chat-reply-dismiss]',
    replyText: '[data-chat-reply-text]',
    replyName: '[data-chat-reply-name]',
    headerCount: '[data-chat-count]',
    composer: '[data-chat-composer]',
    form: '[data-chat-form]',
    search: '[data-chat-search]',
    emptyState: '[data-chat-empty]',
    sidebarToggle: '[data-chat-sidebar-toggle]',
    membersToggle: '[data-chat-members-toggle]',
    sidebar: '[data-chat-sidebar]',
    sidebarOverlay: '[data-chat-sidebar-overlay]',
    themeToggle: '[data-chat-theme-toggle]',
    micButton: '[data-chat-mic]',
    attachButton: '[data-chat-attach]',
    imageButton: '[data-chat-image]',
    fileInput: '[data-chat-file-input]',
    imageInput: '[data-chat-image-input]',
    emojiButton: '[data-chat-emoji]',
    emojiPicker: '[data-chat-emoji-picker]',
    emojiList: '[data-chat-emoji-list]',
    emojiClose: '[data-chat-emoji-close]',
  };

  const state = {
    messages: [],
    users: [],
    latestTimestamp: null,
    lastMessageId: 0,
    replyTo: null,
    editMessage: null,
    statusTimer: null,
    pollingTimer: null,
    isSending: false,
    initialized: false,
    pendingFetchTimeout: null,
    mediaRecorder: null,
    isRecording: false,
    audioChunks: [],
    audioStream: null, // حفظ stream لإيقافه لاحقاً
    pendingMessages: new Map(), // لتتبع الرسائل المؤقتة أثناء الإرسال
  };

  const elements = {};

  const currentUser = {
    id: 0,
    name: '',
    role: '',
  };

  function init() {
    const app = document.querySelector(selectors.app);
    if (!app) {
      return;
    }

    elements.app = app;
    elements.messageList = app.querySelector(selectors.messageList);
    elements.userList = app.querySelector(selectors.userList);
    elements.sendButton = app.querySelector(selectors.sendButton);
    elements.input = app.querySelector(selectors.input);
    elements.toast = app.querySelector(selectors.toast);
    elements.replyBar = app.querySelector(selectors.replyBar);
    elements.replyDismiss = app.querySelector(selectors.replyDismiss);
    elements.replyText = app.querySelector(selectors.replyText);
    elements.replyName = app.querySelector(selectors.replyName);
    elements.headerCount = app.querySelector(selectors.headerCount);
    elements.search = app.querySelector(selectors.search);
    elements.emptyState = app.querySelector(selectors.emptyState);
    elements.sidebarToggle = document.querySelector(selectors.sidebarToggle);
    elements.membersToggle = app.querySelector(selectors.membersToggle);
    elements.sidebar = app.querySelector(selectors.sidebar);
    elements.sidebarOverlay = document.querySelector(selectors.sidebarOverlay);
    elements.themeToggle = app.querySelector(selectors.themeToggle);
    elements.micButton = app.querySelector(selectors.micButton);
    elements.attachButton = app.querySelector(selectors.attachButton);
    elements.imageButton = app.querySelector(selectors.imageButton);
    elements.fileInput = document.querySelector(selectors.fileInput);
    elements.imageInput = document.querySelector(selectors.imageInput);
    elements.emojiButton = app.querySelector(selectors.emojiButton);
    elements.emojiPicker = app.querySelector(selectors.emojiPicker);
    elements.emojiList = app.querySelector(selectors.emojiList);
    elements.emojiClose = app.querySelector(selectors.emojiClose);

    currentUser.id = parseInt(app.dataset.currentUserId || '0', 10);
    currentUser.name = app.dataset.currentUserName || '';
    currentUser.role = app.dataset.currentUserRole || '';

    initTheme();
    bindEvents();
    fetchMessages(true);
    startPresenceUpdates();
    startPolling();
    document.addEventListener('visibilitychange', handleVisibilityChange);

    state.initialized = true;
  }

  function bindEvents() {
    if (elements.sendButton) {
      elements.sendButton.addEventListener('click', handleSend);
    }

    if (elements.input) {
      elements.input.addEventListener('keydown', handleInputKeydown);
      elements.input.addEventListener('input', handleInputResize);
      // Initial resize
      handleInputResize();
    }

    if (elements.replyDismiss) {
      elements.replyDismiss.addEventListener('click', clearReplyAndEdit);
    }

    if (elements.messageList) {
      elements.messageList.addEventListener('click', handleMessageListClick);
    }

    if (elements.userList && elements.search) {
      elements.search.addEventListener('input', handleSearchUsers);
    }

    if (elements.sidebarToggle) {
      elements.sidebarToggle.addEventListener('click', toggleSidebar);
    }

    if (elements.membersToggle) {
      elements.membersToggle.addEventListener('click', toggleSidebar);
    }

    if (elements.sidebarOverlay) {
      elements.sidebarOverlay.addEventListener('click', closeSidebar);
    }

    if (elements.themeToggle) {
      elements.themeToggle.addEventListener('click', toggleTheme);
    }

    if (elements.micButton) {
      // استخدام click فقط للتبديل بين البدء والإيقاف
      elements.micButton.addEventListener('click', handleMicClick);
    }

    if (elements.attachButton && elements.fileInput) {
      elements.attachButton.addEventListener('click', () => {
        elements.fileInput.click();
      });
      elements.fileInput.addEventListener('change', handleFileSelect);
    }

    if (elements.imageButton && elements.imageInput) {
      elements.imageButton.addEventListener('click', () => {
        elements.imageInput.click();
      });
      elements.imageInput.addEventListener('change', handleImageSelect);
    }

    if (elements.emojiButton) {
      elements.emojiButton.addEventListener('click', toggleEmojiPicker);
    }

    if (elements.emojiClose) {
      elements.emojiClose.addEventListener('click', closeEmojiPicker);
    }

    // Close emoji picker when clicking outside
    document.addEventListener('click', (e) => {
      if (elements.emojiPicker && elements.emojiPicker.classList.contains('active')) {
        if (!elements.emojiPicker.contains(e.target) && !elements.emojiButton.contains(e.target)) {
          closeEmojiPicker();
        }
      }
    });

    // Initialize emoji picker
    initEmojiPicker();

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 1100) {
        if (elements.sidebar && elements.sidebar.classList.contains('active')) {
          if (!elements.sidebar.contains(e.target) && 
              !elements.sidebarToggle?.contains(e.target) &&
              !elements.membersToggle?.contains(e.target) &&
              !elements.sidebarOverlay.contains(e.target)) {
            closeSidebar();
          }
        }
      }
    });

    // Handle window resize
    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        if (window.innerWidth > 1100) {
          closeSidebar();
        }
      }, 250);
    });

    window.addEventListener('beforeunload', () => {
      if (state.pendingFetchTimeout) {
        window.clearTimeout(state.pendingFetchTimeout);
        state.pendingFetchTimeout = null;
      }
      
      // إيقاف التسجيل الصوتي إذا كان جارياً
      if (state.isRecording) {
        stopRecording();
      }
      
      // إيقاف stream الميكروفون
      if (state.audioStream) {
        state.audioStream.getTracks().forEach(track => {
          track.stop();
          track.enabled = false;
        });
        state.audioStream = null;
      }
      
      stopPresenceUpdates();
      stopPolling();
      updatePresence(false).catch(() => null);
    });
  }

  function handleVisibilityChange() {
    if (!document.hidden) {
      fetchMessages();
    }
  }

  function toggleSidebar() {
    if (!elements.sidebar || !elements.sidebarOverlay) {
      return;
    }
    
    const isActive = elements.sidebar.classList.contains('active');
    if (isActive) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  function openSidebar() {
    if (elements.sidebar) {
      elements.sidebar.classList.add('active');
    }
    if (elements.sidebarOverlay) {
      elements.sidebarOverlay.classList.add('active');
    }
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (elements.sidebar) {
      elements.sidebar.classList.remove('active');
    }
    if (elements.sidebarOverlay) {
      elements.sidebarOverlay.classList.remove('active');
    }
    document.body.style.overflow = '';
  }

  function initTheme() {
    // Check for saved theme preference or default to light mode
    const savedTheme = localStorage.getItem('chat-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
      document.body.classList.add('dark-mode');
      updateThemeIcon(true);
    } else {
      document.body.classList.remove('dark-mode');
      updateThemeIcon(false);
    }
  }

  function toggleTheme() {
    const isDark = document.body.classList.contains('dark-mode');
    
    if (isDark) {
      document.body.classList.remove('dark-mode');
      localStorage.setItem('chat-theme', 'light');
      updateThemeIcon(false);
    } else {
      document.body.classList.add('dark-mode');
      localStorage.setItem('chat-theme', 'dark');
      updateThemeIcon(true);
    }
  }

  function updateThemeIcon(isDark) {
    if (!elements.themeToggle) {
      return;
    }
    
    const icon = elements.themeToggle.querySelector('.chat-theme-icon');
    const text = elements.themeToggle.querySelector('.chat-theme-text');
    
    if (icon) {
      icon.textContent = isDark ? '☀️' : '🌙';
    }
    
    if (text) {
      text.textContent = isDark ? 'الوضع النهاري' : 'الوضع الليلي';
    }
  }

  function handleSearchUsers(event) {
    const value = event.target.value.trim().toLowerCase();
    const items = elements.userList.querySelectorAll('[data-chat-user-item]');

    items.forEach((item) => {
      const name = item.dataset.name || '';
      if (!value || name.toLowerCase().includes(value)) {
        item.style.display = '';
      } else {
        item.style.display = 'none';
      }
    });
  }

  function handleInputKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      handleSend();
    }
  }

  function handleInputResize() {
    if (!elements.input) {
      return;
    }
    elements.input.style.height = 'auto';
    elements.input.style.height = Math.min(elements.input.scrollHeight, 160) + 'px';
  }

  function handleSend() {
    if (state.isSending) {
      return;
    }

    const message = elements.input.value.trim();

    if (!message) {
      return;
    }

    if (state.editMessage) {
      updateMessage(state.editMessage.id, message);
    } else {
      sendMessage(message, state.replyTo ? state.replyTo.id : null);
    }
  }

  function handleMessageListClick(event) {
    const actionButton = event.target.closest('[data-chat-action]');
    if (!actionButton) {
      return;
    }

    const messageElement = actionButton.closest('[data-chat-message-id]');
    if (!messageElement) {
      return;
    }

    const messageId = parseInt(messageElement.dataset.chatMessageId, 10);
    const message = state.messages.find((item) => item.id === messageId);
    if (!message) {
      return;
    }

    const action = actionButton.dataset.chatAction;

    if (action === 'react') {
      const reactionType = actionButton.dataset.reactionType;
      handleReaction(messageId, reactionType);
    } else if (action === 'reply') {
      setReply(message);
    } else if (action === 'edit') {
      // السماح بالتعديل فقط للرسائل الخاصة بالمستخدم وغير المحذوفة
      if (message.user_id !== currentUser.id || message.deleted) {
        showToast('يمكنك تعديل رسائلك فقط', true);
        return;
      }
      setEdit(message);
    } else if (action === 'delete') {
      // السماح بالحذف فقط للرسائل الخاصة بالمستخدم وغير المحذوفة
      if (message.user_id !== currentUser.id || message.deleted) {
        showToast('يمكنك حذف رسائلك فقط', true);
        return;
      }
      confirmDelete(message);
    } else if (action === 'scroll-to-reply') {
      if (!message.reply_to) {
        return;
      }
      scrollToMessage(message.reply_to);
    }
  }

  function setReply(message) {
    state.replyTo = message;
    state.editMessage = null;
    renderReplyBar();
    focusInput();
  }

  function setEdit(message) {
    state.editMessage = message;
    state.replyTo = null;
    renderReplyBar();
    elements.input.value = message.deleted ? '' : message.message_text;
    handleInputResize();
    focusInput(true);
  }

  function clearReplyAndEdit() {
    state.replyTo = null;
    state.editMessage = null;
    renderReplyBar();
  }

  function renderReplyBar() {
    if (!elements.replyBar) {
      return;
    }

    if (state.replyTo) {
      elements.replyBar.classList.add('active');
      elements.replyName.textContent = state.replyTo.user_name || 'مستخدم';
      elements.replyText.textContent = summarizeText(state.replyTo.message_text);
      elements.replyBar.dataset.mode = 'reply';
    } else if (state.editMessage) {
      elements.replyBar.classList.add('active');
      elements.replyName.textContent = 'تعديل رسالة';
      elements.replyText.textContent = summarizeText(state.editMessage.message_text);
      elements.replyBar.dataset.mode = 'edit';
    } else {
      elements.replyBar.classList.remove('active');
      elements.replyName.textContent = '';
      elements.replyText.textContent = '';
      elements.replyBar.dataset.mode = '';
    }
  }

  function summarizeText(text) {
    if (!text) {
      return '';
    }
    const clean = text.replace(/\s+/g, ' ').trim();
    return clean.length > 120 ? `${clean.substring(0, 117)}...` : clean;
  }

  function focusInput(selectAll = false) {
    elements.input.focus({ preventScroll: true });
    if (selectAll) {
      requestAnimationFrame(() => {
        elements.input.setSelectionRange(elements.input.value.length, elements.input.value.length);
      });
    }
  }

  async function sendMessage(message, replyTo) {
    state.isSending = true;
    toggleComposerDisabled(true);

    try {
      const response = await fetch(`${API_BASE}/send_message.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          message,
          reply_to: replyTo,
        }),
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'تعذر إرسال الرسالة');
      }

      elements.input.value = '';
      handleInputResize();
      clearReplyAndEdit();
      appendMessages([data.data], true);
      showToast('تم إرسال الرسالة');
      scrollToBottom(true);
      setTimeout(() => {
        fetchMessages();
      }, 500);
    } catch (error) {
      console.error(error);
      showToast(error.message || 'حدث خطأ أثناء الإرسال', true);
    } finally {
      state.isSending = false;
      toggleComposerDisabled(false);
    }
  }

  async function updateMessage(messageId, message) {
    // التحقق من أن الرسالة تنتمي للمستخدم الحالي
    const msgToUpdate = state.messages.find((m) => m.id === messageId);
    if (!msgToUpdate || msgToUpdate.user_id !== currentUser.id || msgToUpdate.deleted) {
      showToast('يمكنك تعديل رسائلك فقط', true);
      return;
    }

    state.isSending = true;
    toggleComposerDisabled(true);

    try {
      const response = await fetch(`${API_BASE}/update_message.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          message_id: messageId,
          message,
        }),
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'تعذر تعديل الرسالة');
      }

      elements.input.value = '';
      handleInputResize();
      clearReplyAndEdit();
      applyMessageUpdate(data.data);
      showToast('تم تحديث الرسالة');
      setTimeout(() => {
        fetchMessages();
      }, 500);
    } catch (error) {
      console.error(error);
      showToast(error.message || 'حدث خطأ أثناء التعديل', true);
    } finally {
      state.isSending = false;
      toggleComposerDisabled(false);
    }
  }

  function applyMessageUpdate(updated) {
    const index = state.messages.findIndex((item) => item.id === updated.id);
    if (index === -1) {
      return false;
    }

    const before = state.messages[index];
    const serializedBefore = JSON.stringify(before);
    const merged = {
      ...before,
      ...updated,
      edited: 1,
    };
    const serializedAfter = JSON.stringify(merged);
    if (serializedBefore === serializedAfter) {
      return false;
    }

    state.messages[index] = merged;

    renderMessages();
    highlightMessage(updated.id);
    return true;
  }

  function highlightMessage(messageId) {
    if (!elements.messageList) {
      return;
    }
    const target = elements.messageList.querySelector(`[data-chat-message-id="${messageId}"]`);
    if (!target) {
      return;
    }
    target.classList.add('highlight');
    setTimeout(() => {
      target.classList.remove('highlight');
    }, 1200);
  }

  async function confirmDelete(message) {
    // التحقق من أن الرسالة تنتمي للمستخدم الحالي
    if (message.user_id !== currentUser.id || message.deleted) {
      showToast('يمكنك حذف رسائلك فقط', true);
      return;
    }

    const confirmed = window.confirm('هل أنت متأكد من حذف هذه الرسالة؟');
    if (!confirmed) {
      return;
    }

    try {
      const response = await fetch(`${API_BASE}/delete_message.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          message_id: message.id,
        }),
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'تعذر حذف الرسالة');
      }

      clearReplyAndEdit();
      applyMessageUpdate(data.data);
      showToast('تم حذف الرسالة');
      setTimeout(() => {
        fetchMessages();
      }, 500);
    } catch (error) {
      console.error(error);
      showToast(error.message || 'حدث خطأ أثناء الحذف', true);
    }
  }

  async function handleReaction(messageId, reactionType) {
    try {
      const response = await fetch(`${API_BASE}/react_message.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          message_id: messageId,
          reaction_type: reactionType,
        }),
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'تعذر إضافة التفاعل');
      }

      // Update message in state
      const message = state.messages.find((m) => m.id === messageId);
      if (message) {
        message.thumbs_up_count = data.data.thumbs_up_count || 0;
        message.thumbs_down_count = data.data.thumbs_down_count || 0;
        renderMessages();
      }
    } catch (error) {
      console.error(error);
      showToast(error.message || 'حدث خطأ أثناء إضافة التفاعل', true);
    }
  }

  function toggleComposerDisabled(disabled) {
    elements.sendButton.disabled = disabled;
    elements.input.disabled = disabled;
  }

  async function fetchMessages(initial = false) {
    try {
      const params = new URLSearchParams();
      if (state.latestTimestamp) {
        params.set('since', state.latestTimestamp);
      }
      if (state.lastMessageId) {
        params.set('after_id', state.lastMessageId);
      }

      const response = await fetch(`${API_BASE}/get_messages.php?${params.toString()}`, {
        credentials: 'include',
      });

      if (!response.ok) {
        throw new Error('فشل في تحميل الرسائل');
      }

      const payload = await response.json();

      if (!payload.success) {
        throw new Error(payload.error || 'خطأ غير متوقع');
      }

      const { messages, latest_timestamp: latestTimestamp, users } = payload.data;

      if (Array.isArray(users)) {
        state.users = users;
        updateUserList();
      }

      let hasNewMessages = false;

      if (Array.isArray(messages) && messages.length) {
        hasNewMessages = appendMessages(messages, initial);
      } else if (initial) {
        renderEmptyState(true);
      }

      if (latestTimestamp) {
        state.latestTimestamp = latestTimestamp;
      }

      if (hasNewMessages && !initial) {
        if (state.pendingFetchTimeout) {
          window.clearTimeout(state.pendingFetchTimeout);
        }
        state.pendingFetchTimeout = window.setTimeout(() => {
          state.pendingFetchTimeout = null;
          fetchMessages();
        }, 600);
      }
    } catch (error) {
      console.error(error);
      showToast(error.message || 'تعذر تحديث الرسائل', true);
    }
  }

  function renderEmptyState(show) {
    if (!elements.emptyState) {
      return;
    }
    elements.emptyState.style.display = show ? 'flex' : 'none';
  }

  function appendMessages(newMessages, initial = false) {
    let hasNew = false;
    const existingIds = new Set(state.messages.map((msg) => msg.id));

    newMessages.forEach((message) => {
      if (!existingIds.has(message.id)) {
        state.messages.push(message);
        state.lastMessageId = Math.max(state.lastMessageId, message.id);
        hasNew = message.user_id !== currentUser.id;
      } else if (applyMessageUpdate(message)) {
        hasNew = true;
      }
    });

    state.messages.sort((a, b) => a.id - b.id);
    renderMessages();

    renderEmptyState(state.messages.length === 0);

    if (!initial && hasNew) {
      showToast('رسالة جديدة واردة');
      scrollToBottom();
    } else if (initial) {
      scrollToBottom(true);
    }

    return hasNew;
  }

  function renderMessages() {
    if (!elements.messageList) {
      return;
    }

    const totalUsers = Math.max(1, state.users.length);

    const fragment = document.createDocumentFragment();
    let currentDate = '';
    let lastMessage = null;
    const GROUP_TIME_THRESHOLD = 5 * 60 * 1000; // 5 minutes in milliseconds

    state.messages.forEach((message, index) => {
      const messageDate = formatDate(message.created_at);
      const isNewDay = messageDate !== currentDate;
      if (isNewDay) {
        currentDate = messageDate;
        fragment.appendChild(createDayDivider(messageDate));
        lastMessage = null; // Reset grouping when new day
      }

      // Determine if this message should be grouped with the previous one
      let isGrouped = false;
      if (lastMessage && !isNewDay) {
        const sameUser = message.user_id === lastMessage.user_id;
        const timeDiff = new Date(message.created_at.replace(' ', 'T')).getTime() - 
                        new Date(lastMessage.created_at.replace(' ', 'T')).getTime();
        isGrouped = sameUser && timeDiff < GROUP_TIME_THRESHOLD;
      }

      fragment.appendChild(createMessageElement(message, totalUsers, isGrouped, index === state.messages.length - 1));
      lastMessage = message;
    });

    elements.messageList.innerHTML = '';
    elements.messageList.appendChild(fragment);
    
    // Re-render pending messages after regular messages
    if (state.pendingMessages.size > 0) {
      state.pendingMessages.forEach((pending, pendingId) => {
        const messageElement = createPendingMessageElement(pending, pendingId);
        elements.messageList.appendChild(messageElement);
      });
      scrollToBottom(true);
    }
    
    // Setup audio replay handlers after rendering
    setupAudioReplayHandlers();
  }

  function addPendingMessage(type, fileName = '') {
    const pendingId = 'pending_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    const pendingMessage = {
      id: pendingId,
      type: type, // 'file', 'image', 'audio'
      fileName: fileName,
      timestamp: new Date().toISOString(),
    };
    
    state.pendingMessages.set(pendingId, pendingMessage);
    renderPendingMessages();
    scrollToBottom(true);
    
    return pendingId;
  }

  function removePendingMessage(pendingId) {
    if (state.pendingMessages.has(pendingId)) {
      state.pendingMessages.delete(pendingId);
      renderPendingMessages();
    }
  }

  function renderPendingMessages() {
    if (!elements.messageList) {
      return;
    }

    // Remove existing pending messages
    const existingPending = elements.messageList.querySelectorAll('[data-pending-message]');
    existingPending.forEach(el => el.remove());

    // Add all pending messages at the end
    state.pendingMessages.forEach((pending, pendingId) => {
      const messageElement = createPendingMessageElement(pending, pendingId);
      elements.messageList.appendChild(messageElement);
    });
    
    if (state.pendingMessages.size > 0) {
      requestAnimationFrame(() => {
        scrollToBottom(true);
      });
    }
  }

  function createPendingMessageElement(pending, pendingId) {
    const outgoing = true; // Always outgoing for pending messages
    const messageElement = document.createElement('div');
    messageElement.className = `chat-message outgoing pending`;
    messageElement.dataset.pendingMessage = pendingId;

    const bubble = document.createElement('div');
    bubble.className = 'chat-message-bubble pending-message';

    const content = document.createElement('div');
    content.className = 'chat-message-content';

    const body = document.createElement('div');
    body.className = 'chat-message-body';
    
    let messageText = '';
    if (pending.type === 'audio') {
      messageText = '🎤 جاري إرسال التسجيل الصوتي...';
    } else if (pending.type === 'image') {
      messageText = '🖼️ جاري إرسال الصورة...';
    } else {
      messageText = `📎 جاري إرسال الملف: ${escapeHTML(pending.fileName)}...`;
    }
    
    body.innerHTML = `
      <div class="pending-message-content">
        <span class="pending-spinner"></span>
        <span class="pending-text">${messageText}</span>
      </div>
    `;
    
    content.appendChild(body);
    bubble.appendChild(content);
    messageElement.appendChild(bubble);

    return messageElement;
  }

  function setupAudioReplayHandlers() {
    if (!elements.messageList) {
      return;
    }
    
    const audioElements = elements.messageList.querySelectorAll('audio');
    audioElements.forEach((audioEl) => {
      // Remove existing listeners to avoid duplicates
      const newAudio = audioEl.cloneNode(true);
      audioEl.parentNode.replaceChild(newAudio, audioEl);
      
      // Allow replay after audio ends
      newAudio.addEventListener('ended', function() {
        // Reset to beginning but don't auto-play
        this.currentTime = 0;
      });
      
      // Reset on error to allow retry
      newAudio.addEventListener('error', function() {
        this.load();
      });
      
      // Ensure controls are always available for replay
      newAudio.addEventListener('pause', function() {
        // Keep controls visible
        if (this.ended) {
          this.currentTime = 0;
        }
      });
    });
  }

  function createDayDivider(label) {
    const divider = document.createElement('div');
    divider.className = 'chat-day-divider';
    divider.innerHTML = `<span>${escapeHTML(label)}</span>`;
    return divider;
  }

  function createMessageElement(message, totalUsers, isGrouped = false, isLast = false) {
    const outgoing = message.user_id === currentUser.id;
    const messageElement = document.createElement('div');
    let classes = `chat-message ${outgoing ? 'outgoing' : 'incoming'}`;
    if (isGrouped) {
      classes += ' grouped';
    }
    if (message.deleted) {
      classes += ' deleted';
    }
    if (message.edited && !message.deleted) {
      classes += ' edited';
    }
    messageElement.className = classes;
    messageElement.dataset.chatMessageId = String(message.id);

    // Avatar - only show if not grouped or for incoming messages
    const avatar = document.createElement('div');
    avatar.className = 'chat-message-avatar';
    if (!isGrouped || !outgoing) {
      if (message.profile_photo) {
        avatar.innerHTML = `<img src="${escapeAttribute(message.profile_photo)}" alt="${escapeAttribute(message.user_name)}" />`;
      } else {
        avatar.textContent = getInitials(message.user_name);
      }
    } else {
      // Empty avatar spacer for grouped outgoing messages
      avatar.style.visibility = 'hidden';
    }

    const bubble = document.createElement('div');
    bubble.className = 'chat-message-bubble';

    // Message Header (Name and Time for incoming messages)
    if (!outgoing && !isGrouped) {
      const header = document.createElement('div');
      header.className = 'chat-message-header';
      
      const name = document.createElement('span');
      name.className = 'chat-message-name';
      name.textContent = message.user_name || 'مستخدم';
      header.appendChild(name);
      
      const time = document.createElement('span');
      time.className = 'chat-message-time';
      time.textContent = formatTime(message.created_at);
      header.appendChild(time);
      
      bubble.appendChild(header);
    } else if (!outgoing && isGrouped) {
      // For grouped incoming messages, show time inline
      const time = document.createElement('span');
      time.className = 'chat-message-time inline-time';
      time.textContent = formatTime(message.created_at);
      time.style.display = 'none'; // Hidden by default, shown on hover
      bubble.appendChild(time);
    }

    if (message.reply_to && message.reply_text) {
      const replyFragment = document.createElement('div');
      replyFragment.className = 'chat-reply-preview';
      replyFragment.dataset.chatAction = 'scroll-to-reply';
      replyFragment.innerHTML = `
        <strong>${escapeHTML(message.reply_user_name || 'مستخدم')}</strong>
        <span>${escapeHTML(summarizeText(message.reply_text))}</span>
      `;
      bubble.appendChild(replyFragment);
    }

    const content = document.createElement('div');
    content.className = 'chat-message-content';

    const body = document.createElement('div');
    body.className = 'chat-message-body';
    if (message.deleted) {
      body.textContent = 'تم حذف هذه الرسالة';
    } else {
      body.innerHTML = renderMessageText(message.message_text);
    }
    content.appendChild(body);
    bubble.appendChild(content);

    // Reactions
    const reactions = document.createElement('div');
    reactions.className = 'chat-message-reactions';
    
    const thumbsUp = document.createElement('button');
    thumbsUp.className = 'chat-reaction-button thumbs-up';
    thumbsUp.type = 'button';
    thumbsUp.dataset.chatAction = 'react';
    thumbsUp.dataset.reactionType = 'thumbs_up';
    thumbsUp.dataset.messageId = String(message.id);
    thumbsUp.innerHTML = '👍 <span class="chat-reaction-count" data-reaction-count="thumbs_up">' + (message.thumbs_up_count || 0) + '</span>';
    reactions.appendChild(thumbsUp);
    
    const thumbsDown = document.createElement('button');
    thumbsDown.className = 'chat-reaction-button thumbs-down';
    thumbsDown.type = 'button';
    thumbsDown.dataset.chatAction = 'react';
    thumbsDown.dataset.reactionType = 'thumbs_down';
    thumbsDown.dataset.messageId = String(message.id);
    thumbsDown.innerHTML = '👎 <span class="chat-reaction-count" data-reaction-count="thumbs_down">' + (message.thumbs_down_count || 0) + '</span>';
    reactions.appendChild(thumbsDown);
    
    bubble.appendChild(reactions);

    const meta = document.createElement('div');
    meta.className = 'chat-message-meta';

    // Time for outgoing messages (shown on hover or at end of group)
    if (outgoing) {
      const timeContainer = document.createElement('div');
      timeContainer.className = 'chat-message-time-container';
      const time = document.createElement('span');
      time.className = 'chat-message-time';
      time.textContent = formatTime(message.created_at);
      timeContainer.appendChild(time);
      
      const readSpan = document.createElement('div');
      readSpan.className = 'chat-read-status';
      readSpan.innerHTML = renderReadStatus(message, totalUsers);
      timeContainer.appendChild(readSpan);
      
      meta.appendChild(timeContainer);
    } else {
      meta.appendChild(document.createElement('span'));
    }

    const actions = document.createElement('div');
    actions.className = 'chat-message-actions';

    const replyButton = document.createElement('button');
    replyButton.className = 'chat-message-action-button';
    replyButton.type = 'button';
    replyButton.dataset.chatAction = 'reply';
    replyButton.title = 'رد';
    replyButton.innerHTML = '&#x21a9;';
    actions.appendChild(replyButton);

    if (outgoing && !message.deleted) {
      const editButton = document.createElement('button');
      editButton.className = 'chat-message-action-button';
      editButton.type = 'button';
      editButton.dataset.chatAction = 'edit';
      editButton.title = 'تعديل';
      editButton.innerHTML = '&#9998;';
      actions.appendChild(editButton);

      const deleteButton = document.createElement('button');
      deleteButton.className = 'chat-message-action-button';
      deleteButton.type = 'button';
      deleteButton.dataset.chatAction = 'delete';
      deleteButton.title = 'حذف';
      deleteButton.innerHTML = '&#128465;';
      actions.appendChild(deleteButton);
    }

    meta.appendChild(actions);
    bubble.appendChild(meta);

    messageElement.appendChild(avatar);
    messageElement.appendChild(bubble);

    return messageElement;
  }

  function renderReadStatus(message, totalUsers) {
    const others = Math.max(totalUsers - 1, 0);
    if (others === 0) {
      return '<span>✓</span>';
    }

    const readBy = parseInt(message.read_by_count || 0, 10);
    if (readBy >= others) {
      return '<span style="color: var(--chat-accent)">✓✓</span> تمت القراءة';
    }

    if (readBy > 0) {
      return `<span>✓✓</span> ${readBy}/${others}`;
    }

    return '<span>✓</span> لم تُقرأ بعد';
  }

  function renderMessageText(text) {
    if (!text) {
      return '';
    }

    // استخراج معلومات الملفات المرفقة
    const filePattern = /\[FILE:([^\]]+):([^\]]+)\]/g;
    let processedText = text;
    const fileMatches = [];
    let match;
    
    while ((match = filePattern.exec(text)) !== null) {
      fileMatches.push({
        fullMatch: match[0],
        fileUrl: match[1],
        fileName: match[2] || match[1].split('/').pop()
      });
    }

    // إزالة نمط الملف من النص
    fileMatches.forEach(file => {
      processedText = processedText.replace(file.fullMatch, '');
    });

    // تنظيف النص
    processedText = processedText.trim();
    const escaped = escapeHTML(processedText);
    
    // تحويل الروابط إلى روابط قابلة للنقر
    const withLinks = escaped.replace(
      /(https?:\/\/[^\s]+)/gi,
      (url) => `<a href="${escapeAttribute(url)}" target="_blank" rel="noopener noreferrer">${escapeHTML(url)}</a>`
    );
    
    let result = withLinks.replace(/\n/g, '<br />');
    
    // إضافة عرض الملفات المرفقة
    if (fileMatches.length > 0) {
      const fileHtml = fileMatches.map(file => {
        // تحويل URL إلى API endpoint لتجنب مشكلة CORB
        let fileUrl = file.fileUrl;
        let apiUrl = fileUrl;
        
        // استخراج المسار النسبي من URL الكامل
        // أمثلة:
        // /v1/uploads/chat/images/file_xxx.png -> images/file_xxx.png
        // uploads/chat/images/file_xxx.png -> images/file_xxx.png
        // /uploads/chat/videos/file_xxx.mp4 -> videos/file_xxx.mp4
        
        // إزالة query parameters إذا كانت موجودة
        const urlWithoutQuery = fileUrl.split('?')[0];
        
        // البحث عن المسار بعد uploads/chat/
        const uploadsMatch = urlWithoutQuery.match(/uploads\/chat\/(.+)$/i);
        if (uploadsMatch) {
          const relativePath = uploadsMatch[1];
          apiUrl = `${API_BASE}/get_file.php?path=${encodeURIComponent(relativePath)}`;
        } else {
          // محاولة أخرى: البحث عن chat/ مباشرة
          const chatMatch = urlWithoutQuery.match(/chat\/(images|videos|audio|files)\/(.+)$/i);
          if (chatMatch) {
            const folder = chatMatch[1];
            const filename = chatMatch[2];
            apiUrl = `${API_BASE}/get_file.php?path=${encodeURIComponent(folder + '/' + filename)}`;
          }
        }
        
        const safeFileUrl = escapeAttribute(apiUrl);
        const originalUrl = escapeAttribute(fileUrl);
        const fileName = escapeHTML(file.fileName);
        const fileExtension = fileName.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(fileExtension);
        const isVideo = ['mp4', 'webm', 'ogg', 'mov', 'avi'].includes(fileExtension);
        const isAudio = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'webm'].includes(fileExtension);
        
        if (isImage) {
          return `
            <div class="chat-message-attachment chat-attachment-image">
              <img src="${safeFileUrl}" alt="${fileName}" onclick="window.open('${safeFileUrl}', '_blank')" />
              <div class="chat-attachment-info">
                <span class="chat-attachment-name">${fileName}</span>
                <a href="${safeFileUrl}" download="${fileName}" class="chat-attachment-download">📥 تحميل</a>
              </div>
            </div>
          `;
        } else if (isVideo) {
          return `
            <div class="chat-message-attachment chat-attachment-video">
              <video controls preload="metadata">
                <source src="${safeFileUrl}" type="video/${fileExtension === 'mp4' ? 'mp4' : fileExtension === 'webm' ? 'webm' : 'ogg'}">
                متصفحك لا يدعم تشغيل الفيديو.
              </video>
              <div class="chat-attachment-info">
                <span class="chat-attachment-name">${fileName}</span>
                <a href="${safeFileUrl}" download="${fileName}" class="chat-attachment-download">📥 تحميل</a>
              </div>
            </div>
          `;
        } else if (isAudio) {
          // Generate unique ID for this audio element
          const audioId = 'audio_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
          return `
            <div class="chat-message-attachment chat-attachment-audio">
              <audio id="${audioId}" controls preload="metadata" style="width: 100%; max-width: 250px;">
                <source src="${safeFileUrl}" type="audio/${fileExtension === 'mp3' ? 'mpeg' : fileExtension === 'webm' ? 'webm' : fileExtension}">
                متصفحك لا يدعم تشغيل الصوت.
              </audio>
            </div>
          `;
        } else {
          return `
            <div class="chat-message-attachment chat-attachment-file">
              <div class="chat-attachment-icon">📄</div>
              <div class="chat-attachment-info">
                <span class="chat-attachment-name">${fileName}</span>
                <a href="${safeFileUrl}" download="${fileName}" class="chat-attachment-download">📥 تحميل</a>
              </div>
            </div>
          `;
        }
      }).join('');
      
      result = (result ? result + '<br />' : '') + fileHtml;
    }
    
    return result;
  }

  function getInitials(name) {
    if (!name) {
      return '?';
    }
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) {
      return parts[0].charAt(0).toUpperCase();
    }
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function updateUserList() {
    if (!elements.userList) {
      return;
    }

    if (elements.headerCount) {
      const online = state.users.filter((user) => Number(user.is_online) === 1).length;
      elements.headerCount.textContent = `${online} متصل / ${state.users.length} أعضاء`;
    }

    elements.userList.innerHTML = '';

    const fragment = document.createDocumentFragment();

    state.users.forEach((user) => {
      const item = document.createElement('div');
      item.className = 'chat-user-item';
      item.dataset.chatUserItem = 'true';
      item.dataset.name = user.name || user.username || '';

      const avatar = document.createElement('div');
      avatar.className = 'chat-user-avatar';

      const initials = getInitials(user.name || user.username);
      avatar.textContent = initials;

      const status = document.createElement('div');
      status.className = `chat-user-status ${Number(user.is_online) === 1 ? 'online' : ''}`;
      avatar.appendChild(status);

      const meta = document.createElement('div');
      meta.className = 'chat-user-meta';
      const nameElement = document.createElement('h3');
      nameElement.textContent = user.name || user.username;
      meta.appendChild(nameElement);

      const statusText = document.createElement('span');
      statusText.textContent =
        Number(user.is_online) === 1
          ? 'متصل الآن'
          : `آخر ظهور: ${formatRelativeTime(user.last_seen)}`;
      meta.appendChild(statusText);

      item.appendChild(avatar);
      item.appendChild(meta);
      fragment.appendChild(item);
    });

    elements.userList.appendChild(fragment);
  }

  function startPresenceUpdates() {
    updatePresence(true).catch(() => null);

    if (state.statusTimer) {
      return;
    }

    state.statusTimer = window.setInterval(() => {
      updatePresence(true).catch(() => null);
    }, PRESENCE_INTERVAL);
  }

  function stopPresenceUpdates() {
    if (state.statusTimer) {
      window.clearInterval(state.statusTimer);
      state.statusTimer = null;
    }
  }

  function startPolling() {
    if (state.pollingTimer) {
      return;
    }

    state.pollingTimer = window.setInterval(() => {
      if (!document.hidden && state.initialized) {
        fetchMessages();
      }
    }, POLLING_INTERVAL);
  }

  function stopPolling() {
    if (state.pollingTimer) {
      window.clearInterval(state.pollingTimer);
      state.pollingTimer = null;
    }
  }

  async function updatePresence(isOnline) {
    try {
      await fetch(`${API_BASE}/user_status.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ is_online: Boolean(isOnline) }),
      });
    } catch (error) {
      console.error('presence update failed', error);
    }
  }

  function scrollToBottom(force = false) {
    if (!elements.messageList) {
      return;
    }
    if (!force) {
      const threshold = 120;
      const distanceFromBottom =
        elements.messageList.scrollHeight -
        elements.messageList.scrollTop -
        elements.messageList.clientHeight;

      if (distanceFromBottom > threshold) {
        return;
      }
    }

    requestAnimationFrame(() => {
      elements.messageList.scrollTop = elements.messageList.scrollHeight;
    });
  }

  function scrollToMessage(messageId) {
    const target = elements.messageList.querySelector(
      `[data-chat-message-id="${messageId}"]`
    );
    if (!target) {
      return;
    }

    target.classList.add('highlight');
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
      target.classList.remove('highlight');
    }, 1600);
  }

  function formatDate(dateString) {
    try {
      const date = new Date(dateString.replace(' ', 'T'));
      return date.toLocaleDateString('ar-EG', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      });
    } catch (error) {
      return dateString;
    }
  }

  function formatTime(dateString) {
    try {
      const date = new Date(dateString.replace(' ', 'T'));
      return date.toLocaleTimeString('ar-EG', {
        hour: '2-digit',
        minute: '2-digit',
      });
    } catch (error) {
      return dateString;
    }
  }

  function formatRelativeTime(dateString) {
    if (!dateString) {
      return 'غير معروف';
    }

    const date = new Date(dateString.replace(' ', 'T'));
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const minutes = Math.floor(diff / (1000 * 60));

    if (minutes < 1) {
      return 'الآن';
    }
    if (minutes < 60) {
      return `منذ ${minutes} دقيقة`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
      return `منذ ${hours} ساعة`;
    }
    const days = Math.floor(hours / 24);
    if (days === 1) {
      return 'منذ يوم';
    }
    if (days === 2) {
      return 'منذ يومين';
    }
    if (days < 7) {
      return `منذ ${days} أيام`;
    }
    return date.toLocaleDateString('ar-EG', {
      month: 'short',
      day: 'numeric',
    });
  }

  function showToast(message, isError = false) {
    if (!elements.toast) {
      return;
    }
    elements.toast.textContent = message;
    elements.toast.style.background = isError
      ? 'var(--chat-danger)'
      : 'var(--chat-accent)';
    elements.toast.classList.add('visible');
    setTimeout(() => {
      elements.toast.classList.remove('visible');
    }, 2600);
  }

  function escapeHTML(value) {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
  }

  function escapeAttribute(value) {
    return String(value || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Microphone recording functions
  async function handleMicClick(e) {
    e.preventDefault();
    e.stopPropagation();
    
    if (!state.isRecording) {
      await startRecording();
    } else {
      stopRecording();
    }
  }

  async function startRecording() {
    // إذا كان هناك تسجيل جاري بالفعل، لا تفعل شيئاً
    if (state.isRecording) {
      return;
    }

    try {
      // إيقاف أي stream سابق إذا كان موجوداً
      if (state.audioStream) {
        state.audioStream.getTracks().forEach(track => track.stop());
        state.audioStream = null;
      }

      // الحصول على stream جديد
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      state.audioStream = stream; // حفظ stream في state
      
      // إعداد MediaRecorder
      const options = { mimeType: 'audio/webm' };
      if (!MediaRecorder.isTypeSupported(options.mimeType)) {
        // Fallback للمتصفحات التي لا تدعم webm
        options.mimeType = 'audio/webm;codecs=opus';
        if (!MediaRecorder.isTypeSupported(options.mimeType)) {
          delete options.mimeType; // استخدام الافتراضي
        }
      }
      
      state.mediaRecorder = new MediaRecorder(stream, options);
      state.audioChunks = [];

      state.mediaRecorder.ondataavailable = (event) => {
        if (event.data && event.data.size > 0) {
          state.audioChunks.push(event.data);
        }
      };

      state.mediaRecorder.onstop = async () => {
        // إيقاف stream
        if (state.audioStream) {
          state.audioStream.getTracks().forEach(track => {
            track.stop();
            track.enabled = false;
          });
          state.audioStream = null;
        }

        // التحقق من وجود بيانات مسجلة
        if (state.audioChunks.length === 0) {
          showToast('لم يتم تسجيل أي صوت. حاول مرة أخرى.', true);
          state.audioChunks = [];
          state.isRecording = false;
          updateMicButtonState(false);
          return;
        }

        // إنشاء Blob وإرساله
        const audioBlob = new Blob(state.audioChunks, { type: state.mediaRecorder.mimeType || 'audio/webm' });
        
        // التحقق من حجم الملف (يجب أن يكون أكبر من 0)
        if (audioBlob.size === 0) {
          showToast('التسجيل فارغ. حاول مرة أخرى.', true);
          state.audioChunks = [];
          state.isRecording = false;
          updateMicButtonState(false);
          return;
        }

        await sendAudioMessage(audioBlob);
        
        // تنظيف
        state.audioChunks = [];
        state.isRecording = false;
        updateMicButtonState(false);
      };

      state.mediaRecorder.onerror = (event) => {
        console.error('MediaRecorder error:', event.error);
        showToast('حدث خطأ أثناء التسجيل', true);
        stopRecording();
      };

      // بدء التسجيل مع timeslice لضمان الحصول على البيانات
      state.mediaRecorder.start(1000); // جمع البيانات كل ثانية
      state.isRecording = true;
      
      updateMicButtonState(true);
      showToast('جاري التسجيل... اضغط مرة أخرى لإيقاف التسجيل');
    } catch (error) {
      console.error('Error starting recording:', error);
      state.isRecording = false;
      updateMicButtonState(false);
      
      if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
        showToast('تم رفض الوصول إلى الميكروفون. يرجى السماح بالوصول في إعدادات المتصفح.', true);
      } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
        showToast('لم يتم العثور على ميكروفون. تأكد من توصيل ميكروفون.', true);
      } else {
        showToast('تعذر الوصول إلى الميكروفون. تأكد من السماح بالوصول.', true);
      }
      
      // تنظيف في حالة الخطأ
      if (state.audioStream) {
        state.audioStream.getTracks().forEach(track => track.stop());
        state.audioStream = null;
      }
    }
  }

  function stopRecording() {
    if (!state.isRecording) {
      return;
    }

    if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
      try {
        // إيقاف التسجيل
        if (state.mediaRecorder.state === 'recording') {
          state.mediaRecorder.stop();
        }
        
        updateMicButtonState(false);
        showToast('تم إيقاف التسجيل... جاري الإرسال');
      } catch (error) {
        console.error('Error stopping recording:', error);
        // فرض الإيقاف حتى لو حدث خطأ
        state.isRecording = false;
        updateMicButtonState(false);
        
        // إيقاف stream
        if (state.audioStream) {
          state.audioStream.getTracks().forEach(track => {
            track.stop();
            track.enabled = false;
          });
          state.audioStream = null;
        }
      }
    } else {
      // إذا لم يكن هناك recorder نشط، تأكد من إيقاف stream
      state.isRecording = false;
      updateMicButtonState(false);
      
      if (state.audioStream) {
        state.audioStream.getTracks().forEach(track => {
          track.stop();
          track.enabled = false;
        });
        state.audioStream = null;
      }
    }
  }

  function updateMicButtonState(recording) {
    if (elements.micButton) {
      if (recording) {
        elements.micButton.classList.add('recording');
        elements.micButton.style.color = 'var(--chat-red)';
      } else {
        elements.micButton.classList.remove('recording');
        elements.micButton.style.color = '';
      }
    }
  }

  async function sendAudioMessage(audioBlob) {
    let pendingId = null;
    try {
      // التحقق من حجم الملف
      if (!audioBlob || audioBlob.size === 0) {
        showToast('التسجيل فارغ. حاول مرة أخرى.', true);
        return;
      }

      // إضافة رسالة مؤقتة
      pendingId = addPendingMessage('audio', 'تسجيل صوتي');

      const formData = new FormData();
      formData.append('audio', audioBlob, 'recording.webm');
      formData.append('reply_to', state.replyTo ? state.replyTo.id : '');

      const response = await fetch(`${API_BASE}/send_audio.php`, {
        method: 'POST',
        credentials: 'include',
        body: formData,
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'تعذر إرسال التسجيل الصوتي');
      }

      // إزالة الرسالة المؤقتة
      if (pendingId) {
        removePendingMessage(pendingId);
      }

      clearReplyAndEdit();
      appendMessages([data.data], true);
      showToast('تم إرسال التسجيل الصوتي');
      scrollToBottom(true);
      setTimeout(() => {
        fetchMessages();
      }, 500);
    } catch (error) {
      console.error('Error sending audio:', error);
      if (pendingId) {
        removePendingMessage(pendingId);
      }
      showToast(error.message || 'حدث خطأ أثناء إرسال التسجيل', true);
    }
  }

  // File attachment functions
  function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) {
      return;
    }

    if (file.size > 50 * 1024 * 1024) { // 50MB limit
      showToast('حجم الملف كبير جداً. الحد الأقصى 50 ميجابايت', true);
      return;
    }

    sendFile(file);
    event.target.value = ''; // Reset input
  }

  function handleImageSelect(event) {
    const file = event.target.files[0];
    if (!file) {
      return;
    }

    if (file.size > 50 * 1024 * 1024) { // 50MB limit
      showToast('حجم الملف كبير جداً. الحد الأقصى 50 ميجابايت', true);
      return;
    }

    sendFile(file);
    event.target.value = ''; // Reset input
  }

  async function sendFile(file) {
    let pendingId = null;
    try {
      state.isSending = true;
      toggleComposerDisabled(true);

      // تحديد نوع الملف
      const isImage = file.type.startsWith('image/');
      const fileType = isImage ? 'image' : 'file';
      
      // إضافة رسالة مؤقتة
      pendingId = addPendingMessage(fileType, file.name);

      const formData = new FormData();
      formData.append('file', file);
      formData.append('reply_to', state.replyTo ? state.replyTo.id : '');

      const response = await fetch(`${API_BASE}/send_file.php`, {
        method: 'POST',
        credentials: 'include',
        body: formData,
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.error || 'تعذر إرسال الملف');
      }

      // إزالة الرسالة المؤقتة
      if (pendingId) {
        removePendingMessage(pendingId);
      }

      clearReplyAndEdit();
      appendMessages([data.data], true);
      showToast('تم إرسال الملف');
      scrollToBottom(true);
      setTimeout(() => {
        fetchMessages();
      }, 500);
    } catch (error) {
      console.error(error);
      if (pendingId) {
        removePendingMessage(pendingId);
      }
      showToast(error.message || 'حدث خطأ أثناء إرسال الملف', true);
    } finally {
      state.isSending = false;
      toggleComposerDisabled(false);
    }
  }

  // Emoji picker functions
  function initEmojiPicker() {
    if (!elements.emojiList) {
      return;
    }

    // قائمة الإيموجي الشائعة
    const emojis = [
      '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃',
      '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😙',
      '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭', '🤫', '🤔',
      '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '🤥',
      '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🤢', '🤮',
      '🤧', '🥵', '🥶', '😶‍🌫️', '😵', '😵‍💫', '🤯', '🤠', '🥳', '😎',
      '🤓', '🧐', '😕', '😟', '🙁', '😮', '😯', '😲', '😳', '🥺',
      '😦', '😧', '😨', '😰', '😥', '😢', '😭', '😱', '😖', '😣',
      '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬', '😈',
      '👿', '💀', '☠️', '💩', '🤡', '👹', '👺', '👻', '👽', '👾',
      '🤖', '😺', '😸', '😹', '😻', '😼', '😽', '🙀', '😿', '😾',
      '👋', '🤚', '🖐', '✋', '🖖', '👌', '🤌', '🤏', '✌️', '🤞',
      '🤟', '🤘', '🤙', '👈', '👉', '👆', '🖕', '👇', '☝️', '👍',
      '👎', '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝',
      '🙏', '✍️', '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃',
      '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
      '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️',
      '✝️', '☪️', '🕉', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐',
      '⛎', '♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐',
      '♑', '♒', '♓', '🆔', '⚛️', '🉑', '☢️', '☣️', '📴', '📳',
      '🈶', '🈚', '🈸', '🈺', '🈷️', '✴️', '🆚', '💮', '🉐', '㊙️',
      '㊗️', '🈴', '🈵', '🈹', '🈲', '🅰️', '🅱️', '🆎', '🆑', '🅾️',
      '🆘', '❌', '⭕', '🛑', '⛔', '📛', '🚫', '💯', '💢', '♨️',
      '🚷', '🚯', '🚳', '🚱', '🔞', '📵', '🚭', '❗', '❕', '❓',
      '❔', '‼️', '⁉️', '🔅', '🔆', '〽️', '⚠️', '🚸', '🔱', '⚜️',
      '🔰', '♻️', '✅', '🈯', '💹', '❇️', '✳️', '❎', '🌐', '💠',
      'Ⓜ️', '🌀', '💤', '🏧', '🚾', '♿', '🅿️', '🈳', '🈂️', '🛂',
      '🛃', '🛄', '🛅', '🚹', '🚺', '🚼', '🚻', '🚮', '🎦', '📶',
      '🈁', '🔣', 'ℹ️', '🔤', '🔡', '🔠', '🆖', '🆗', '🆙', '🆒',
      '🆕', '🆓', '0️⃣', '1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣',
      '8️⃣', '9️⃣', '🔟', '🔢', '#️⃣', '*️⃣', '▶️', '⏸', '⏯', '⏹',
      '⏺', '⏭', '⏮', '⏩', '⏪', '⏫', '⏬', '◀️', '🔼', '🔽',
      '➡️', '⬅️', '⬆️', '⬇️', '↗️', '↘️', '↙️', '↖️', '↕️', '↔️',
      '↪️', '↩️', '⤴️', '⤵️', '🔀', '🔁', '🔂', '🔄', '🔃', '🎵',
      '🎶', '➕', '➖', '➗', '✖️', '💲', '💱', '™️', '©️', '®️',
      '〰️', '➰', '➿', '🔚', '🔙', '🔛', '🔜', '🔝', '🛐', '⚛️',
      '🕉️', '☸️', '☮️', '☪️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐',
    ];

    // إنشاء أزرار الإيموجي
    emojis.forEach(emoji => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'chat-emoji-item';
      button.textContent = emoji;
      button.title = emoji;
      button.addEventListener('click', () => insertEmoji(emoji));
      elements.emojiList.appendChild(button);
    });
  }

  function toggleEmojiPicker() {
    if (!elements.emojiPicker) {
      return;
    }
    
    const isActive = elements.emojiPicker.classList.contains('active');
    if (isActive) {
      closeEmojiPicker();
    } else {
      openEmojiPicker();
    }
  }

  function openEmojiPicker() {
    if (elements.emojiPicker) {
      elements.emojiPicker.classList.add('active');
    }
  }

  function closeEmojiPicker() {
    if (elements.emojiPicker) {
      elements.emojiPicker.classList.remove('active');
    }
  }

  function insertEmoji(emoji) {
    if (!elements.input) {
      return;
    }

    const cursorPos = elements.input.selectionStart || elements.input.value.length;
    const textBefore = elements.input.value.substring(0, cursorPos);
    const textAfter = elements.input.value.substring(cursorPos);
    
    elements.input.value = textBefore + emoji + textAfter;
    elements.input.focus();
    
    // وضع المؤشر بعد الإيموجي
    const newCursorPos = cursorPos + emoji.length;
    elements.input.setSelectionRange(newCursorPos, newCursorPos);
    
    handleInputResize();
    closeEmojiPicker();
  }

  document.addEventListener('DOMContentLoaded', init);

  window.addEventListener('beforeunload', () => {
    if (state.pendingFetchTimeout) {
      window.clearTimeout(state.pendingFetchTimeout);
      state.pendingFetchTimeout = null;
    }
    
    // إيقاف التسجيل الصوتي إذا كان جارياً
    if (state.isRecording) {
      stopRecording();
    }
    
    // إيقاف stream الميكروفون
    if (state.audioStream) {
      state.audioStream.getTracks().forEach(track => {
        track.stop();
        track.enabled = false;
      });
      state.audioStream = null;
    }
    
    stopPolling();
    stopPresenceUpdates();
  });

  // إيقاف polling و presence عند إخفاء الصفحة لتقليل الضغط
  document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
      stopPolling();
      stopPresenceUpdates();
    } else {
      if (state.initialized) {
        startPolling();
        startPresenceUpdates();
      }
    }
  });
  
  // دالة للتعامل مع الرسائل من unified polling system
  window.handleChatMessages = function(messages) {
    if (!Array.isArray(messages) || messages.length === 0) {
      return;
    }
    
    try {
      let hasNew = false;
      const existingIds = new Set(state.messages.map((msg) => msg.id));
      
      messages.forEach((message) => {
        if (!existingIds.has(message.id)) {
          // تحويل format الرسالة إذا لزم الأمر
          const formattedMessage = {
            id: message.id,
            user_id: message.user_id || message.userId,
            chat_id: message.chat_id || message.chatId,
            message: message.message || message.content,
            created_at: message.created_at || message.createdAt,
            user_name: message.user_name || message.userName,
            user_role: message.user_role || message.userRole
          };
          
          state.messages.push(formattedMessage);
          state.lastMessageId = Math.max(state.lastMessageId, formattedMessage.id);
          hasNew = formattedMessage.user_id !== currentUser.id;
        } else {
          // تحديث الرسالة الموجودة إذا تغيرت
          const existingIndex = state.messages.findIndex(m => m.id === message.id);
          if (existingIndex !== -1) {
            if (applyMessageUpdate(message)) {
              hasNew = true;
            }
          }
        }
      });
      
      // ترتيب الرسائل
      if (hasNew) {
        state.messages.sort((a, b) => a.id - b.id);
        renderMessages();
        scrollToBottom();
      }
      
      // تحديث lastChatMessageId للـ unified polling
      if (messages.length > 0) {
        const lastMsg = messages[messages.length - 1];
        if (lastMsg && lastMsg.id) {
          window.lastChatMessageId = lastMsg.id;
        }
      }
    } catch (error) {
      console.error('Error handling unified chat messages:', error);
    }
  };
  
  // تحديث currentChatId إذا كان متاحاً
  if (elements.app && elements.app.dataset.chatId) {
    window.currentChatId = parseInt(elements.app.dataset.chatId, 10);
  }
})();

