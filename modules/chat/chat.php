<?php
/**
 * Professional Chat Interface
 * 
 * Provides real-time messaging, user presence tracking, and attachment support.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requirePermissionOrRole('chat.page', []);
ensureChatSchema();
ensureCsrfToken();

$user = getCurrentUser();
?>
<?php $pageTitle = 'Chat'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
    <style>
        .chat-input-wrapper {
            overflow: visible !important;
        }
        .emoji-picker-popup {
            position: absolute;
            bottom: 100%;
            left: 0;
            z-index: 1050;
            width: 280px;
            margin-bottom: 15px;
            background: #fff;
        }
        .emoji-item {
            font-size: 1.2rem;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .emoji-item:hover {
            background: #f1f3f5 !important;
            transform: scale(1.15);
        }
        .emoji-list::-webkit-scrollbar {
            width: 5px;
        }
        .emoji-list::-webkit-scrollbar-thumb {
            background: #dee2e6;
            border-radius: 10px;
        }
    </style>
    <div class="container-fluid px-0">
        <div class="chat-container">
            <!-- Sidebar -->
            <div class="chat-sidebar">
                <div class="sidebar-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="mb-0 fw-bold">Messages</h5>
                        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="modal" data-bs-target="#createGroupModal" title="New Group">
                            <i class="bi bi-people"></i>
                        </button>
                    </div>
                    <div class="mt-3">
                        <div class="input-group input-group-sm chat-search-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="chatSearch" placeholder="Search users / groups" autocomplete="off" aria-label="Search chats">
                            <div class="btn-group dropdown">
                                <button class="btn btn-light border dropdown-toggle" type="button" id="chatFilterBtn" data-bs-toggle="dropdown" data-bs-auto-close="true" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-haspopup="true" aria-controls="chatFilterMenu">All</button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" id="chatFilterMenu" role="menu" aria-labelledby="chatFilterBtn">
                                    <li><button class="dropdown-item active" type="button" role="menuitem" data-filter="all">All</button></li>
                                    <li><button class="dropdown-item" type="button" role="menuitem" data-filter="direct">Direct Chats</button></li>
                                    <li><button class="dropdown-item" type="button" role="menuitem" data-filter="groups">Group Chats</button></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="user-list" id="userList">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    </div>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="chat-main">
                <div id="chatInterface" class="chat-interface d-none">
                    <div class="chat-header">
                        <div class="d-flex align-items-center gap-3">
                            <button class="btn btn-sm btn-light border d-lg-none" type="button" id="chatBackBtn" title="Back" onclick="closeMobileChat()">
                                <i class="bi bi-arrow-left"></i>
                            </button>
                            <div class="user-avatar" id="activePeerAvatar"></div>
                            <div>
                                <h6 class="mb-0 fw-bold" id="activePeerName"></h6>
                                <span class="user-status d-flex align-items-center gap-1">
                                    <span id="activePeerStatusDot" class="status-indicator status-dot-sm position-static"></span>
                                    <span id="activePeerStatusText"></span>
                                </span>
                            </div>
                        </div>
                        <div class="chat-actions">
                            <button class="btn btn-sm btn-light border d-none" id="groupMembersBtn" type="button" data-bs-toggle="modal" data-bs-target="#groupMembersModal" title="Members">
                                <i class="bi bi-people"></i>
                            </button>
                            <button class="btn btn-sm btn-light border d-none" id="chatInfoBtn" type="button" title="Info" onclick="toggleChatInfo()">
                                <i class="bi bi-info-circle"></i>
                            </button>
                            <button class="btn btn-sm btn-light border" onclick="loadChatList()"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>

                    <div class="chat-messages" id="chatMessages">
                        <!-- Messages loaded via JS -->
                    </div>

                    <div class="chat-footer">
                        <form id="chatForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="receiver_id" id="receiverIdInput">
                            <input type="hidden" name="group_id" id="groupIdInput">
                            <input type="hidden" name="reply_to_id" id="replyToIdInput" value="0">
                            <div class="chat-input-wrapper position-relative">
                                <div id="replyPreview" class="d-none mb-2 px-2 py-2 rounded-3 border bg-light-subtle">
                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                        <div class="flex-grow-1">
                                            <div class="small fw-semibold text-muted" id="replyPreviewTitle"></div>
                                            <div class="small" id="replyPreviewText"></div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-light border" onclick="clearReplyTo()" title="Cancel reply"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                                <label class="attachment-btn mb-0" for="attachmentInput" title="Attach File">
                                    <i class="bi bi-paperclip fs-5"></i>
                                    <input type="file" name="attachment" id="attachmentInput" class="d-none">
                                </label>
                                <button type="button" class="btn btn-link text-muted p-0 me-2" id="emojiPickerBtn" title="Emojis">
                                    <i class="bi bi-emoji-smile fs-5"></i>
                                </button>
                                <input type="text" class="form-control" name="message" id="messageInput" placeholder="Type your message here..." autocomplete="off">
                                <button type="submit" class="btn btn-primary rounded-pill px-4 ms-2">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                                
                                <!-- Emoji Picker Popup -->
                                <div id="emojiPicker" class="emoji-picker-popup d-none shadow-lg border rounded-3 bg-white">
                                    <div class="emoji-picker-header p-2 border-bottom d-flex justify-content-between align-items-center">
                                        <span class="small fw-bold text-muted">Select Emoji</span>
                                        <button type="button" class="btn-close" style="font-size: 0.7rem;" onclick="toggleEmojiPicker()"></button>
                                    </div>
                                    <div class="emoji-list p-2" style="max-height: 250px; overflow-y: auto;">
                                        <div id="recentEmojisContainer" class="mb-2 d-none">
                                            <div class="x-small fw-bold text-muted mb-1 px-1 text-uppercase" style="font-size: 0.65rem;">Recently Used</div>
                                            <div id="recentEmojiList" class="d-flex flex-wrap gap-1"></div>
                                            <hr class="my-2 mx-1 opacity-25">
                                        </div>
                                        <div class="x-small fw-bold text-muted mb-1 px-1 text-uppercase" style="font-size: 0.65rem;">All Emojis</div>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php
                                                $emojis = [
                                                    '😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻','💀','☠️','👽','👾','🤖','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾','🤲','👐','🙌','👏','🤝','👍','👎','👊','✊','🤛','🤜','🤞','✌️','🤟','🤘','👌','🤏','👈','👉','👆','👇','☝️','✋','🤚','🖐','🖖','👋','🤙','💪','🦾','🖕','✍️','🙏','🦶','🦵','🦿','💄','💋','👄','🦷','👅','👂','🦻','👃','👣','👁','👀','🧠','🗣','👤','👥'
                                                ];
                                                foreach ($emojis as $e) {
                                                    echo '<button type="button" class="btn btn-sm btn-light p-1 border-0 emoji-item" onclick="insertEmoji(\''.$e.'\')">'.$e.'</button>';
                                                }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="attachmentPreview" class="small text-muted mt-2 d-none">
                                <i class="bi bi-file-earmark-check me-1"></i> <span id="fileName"></span>
                                <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" onclick="clearAttachment()">Remove</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Empty State -->
                <div class="empty-chat" id="emptyChat">
                    <i class="bi bi-chat-dots"></i>
                    <h4>Select a conversation</h4>
                    <p>Choose a user from the left sidebar to start messaging.</p>
                </div>
            </div>

            <div class="chat-info d-none d-lg-flex" id="chatInfoPanel">
                <div class="chat-info-header">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <div class="fw-semibold text-truncate" id="chatInfoTitle">Conversation</div>
                        <button class="btn btn-sm btn-light border" type="button" onclick="toggleChatInfo()" title="Close"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <ul class="nav nav-pills nav-pills-sm mt-2" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#chatInfoMembers" type="button" role="tab">Members</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#chatInfoFiles" type="button" role="tab">Files</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#chatInfoMedia" type="button" role="tab">Media</button></li>
                    </ul>
                </div>
                <div class="chat-info-body tab-content">
                    <div class="tab-pane fade show active" id="chatInfoMembers" role="tabpanel">
                        <div id="chatInfoMembersBody" class="text-muted small p-3">Select a group chat to see members.</div>
                    </div>
                    <div class="tab-pane fade" id="chatInfoFiles" role="tabpanel">
                        <div id="chatInfoFilesBody" class="text-muted small p-3">No files.</div>
                    </div>
                    <div class="tab-pane fade" id="chatInfoMedia" role="tabpanel">
                        <div id="chatInfoMediaBody" class="text-muted small p-3">No media.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="createGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-people me-1"></i>Create Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Group Name</label>
                            <input type="text" class="form-control" id="newGroupName" placeholder="e.g. Operations Team">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Add Members</label>
                            <input type="text" class="form-control form-control-sm mb-2" id="groupMemberSearch" placeholder="Search users">
                            <div class="list-group" id="groupMemberPickList"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createGroup()"><i class="bi bi-check2 me-1"></i>Create</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="groupMembersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-people me-1"></i>Group Members</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="groupMembersBody" class="text-muted">Loading...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let activeChat = null;
        let lastMessageId = 0;
        const csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
        const currentUserId = <?php echo (int)$user['id']; ?>;
        <?php
            $chatApiBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/modules/chat/chat.php')), '/') . '/';
        ?>
        const CHAT_API = <?php echo json_encode($chatApiBase); ?>;
        const chatUrl = (path) => {
            const p = String(path || '').replace(/^\//, '');
            return CHAT_API + p;
        };

        function isMobileChatUi() {
            return window.matchMedia && window.matchMedia('(max-width: 992px)').matches;
        }

        function setMobileChatOpen(open) {
            const box = document.querySelector('.chat-container');
            if (!box) return;
            if (isMobileChatUi()) box.classList.toggle('chat-mobile-chat-open', !!open);
            else box.classList.remove('chat-mobile-chat-open');
        }

        function closeMobileChat() {
            toggleChatInfo(false);
            setMobileChatOpen(false);
        }
        let chatFilter = 'all';
        let groupRole = 'member';
        let groupId = null;
        let peerId = null;
        let allUsersForGroupPick = [];

        function getInitials(name) {
            return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
        }

        const CHAT_FILTER_LABELS = { all: 'All', direct: 'Direct', groups: 'Groups' };

        function setChatFilter(f, itemEl) {
            const allowed = ['all', 'direct', 'groups'];
            chatFilter = allowed.includes(f) ? f : 'all';
            const btn = document.getElementById('chatFilterBtn');
            if (btn) btn.textContent = CHAT_FILTER_LABELS[chatFilter] || 'All';
            document.querySelectorAll('#chatFilterMenu .dropdown-item').forEach(el => {
                el.classList.toggle('active', el.getAttribute('data-filter') === chatFilter);
            });
            if (itemEl && btn && window.bootstrap && bootstrap.Dropdown) {
                const dd = bootstrap.Dropdown.getInstance(btn) || bootstrap.Dropdown.getOrCreateInstance(btn);
                dd.hide();
            }
            loadChatList();
        }

        function avatarHtml(name, profilePic) {
            const safeName = escapeHtml(name || '');
            if (profilePic) {
                return `<img src="../../${escapeHtml(profilePic)}" alt="${safeName}" style="width:100%;height:100%;object-fit:cover;border-radius:999px;">`;
            }
            return `<div class="w-100 h-100 d-flex align-items-center justify-content-center">${escapeHtml(getInitials(name || 'U'))}</div>`;
        }

        function groupAvatarHtml(name) {
            return `<div class="w-100 h-100 d-flex align-items-center justify-content-center"><i class="bi bi-people"></i></div>`;
        }

        function formatTime(ts) {
            if (!ts) return '';
            const d = new Date(ts.replace(' ', 'T'));
            if (isNaN(d.getTime())) return String(ts);
            return d.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        }

        function loadChatList() {
            const q = (document.getElementById('chatSearch')?.value || '').trim();
            const filter = ['all', 'direct', 'groups'].includes(chatFilter) ? chatFilter : 'all';
            fetch(chatUrl(`chat-list?filter=${encodeURIComponent(filter)}&q=${encodeURIComponent(q)}`), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(data => {
                    const list = document.getElementById('userList');
                    if (!data.ok) { list.innerHTML = '<div class="p-3 text-danger">Error loading chats</div>'; return; }
                    const showGroups = filter === 'all' || filter === 'groups';
                    const showDirect = filter === 'all' || filter === 'direct';
                    const direct = showDirect && Array.isArray(data.direct_chats) ? data.direct_chats : [];
                    const groups = showGroups && Array.isArray(data.group_chats) ? data.group_chats : [];

                    let html = '';
                    if (groups.length) {
                        if (filter === 'all') html += `<div class="px-3 pt-3 pb-2 text-muted small fw-semibold">Group Chats</div>`;
                        groups.forEach(g => {
                            const isActive = activeChat && activeChat.type === 'group' && activeChat.id === g.id;
                            html += `
                                <div class="user-item ${isActive ? 'active' : ''}" data-chat-type="group" data-id="${g.id}" data-name="${escapeAttr(g.name || '')}" data-members="${g.member_count || 0}" data-role="${escapeAttr(g.member_role || 'member')}">
                                    <div class="user-avatar">
                                        ${groupAvatarHtml(g.name)}
                                    </div>
                                    <div class="user-info">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <div class="user-name text-truncate">${escapeHtml(g.name)}</div>
                                            <div class="text-muted small">${escapeHtml(formatTime(g.last_message_at))}</div>
                                        </div>
                                        <div class="user-status text-truncate">${escapeHtml(g.last_message_preview || '')}</div>
                                    </div>
                                    ${g.unread_count > 0 ? `<span class="badge rounded-pill bg-danger">${g.unread_count}</span>` : ''}
                                </div>
                            `;
                        });
                    }
                    if (direct.length) {
                        if (filter === 'all') html += `<div class="px-3 pt-3 pb-2 text-muted small fw-semibold">Direct Chats</div>`;
                        direct.forEach(u => {
                            const isActive = activeChat && activeChat.type === 'direct' && activeChat.id === u.id;
                            html += `
                                <div class="user-item ${isActive ? 'active' : ''}" data-chat-type="direct" data-id="${u.id}" data-name="${escapeAttr(u.name || '')}" data-online="${u.is_online ? '1' : '0'}" data-pic="${escapeAttr(u.profile_pic || '')}">
                                    <div class="user-avatar">
                                        ${avatarHtml(u.name, u.profile_pic)}
                                        <span class="status-indicator ${u.is_online ? 'status-online' : 'status-offline'}"></span>
                                    </div>
                                    <div class="user-info">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <div class="user-name text-truncate">${escapeHtml(u.name)}</div>
                                            <div class="text-muted small">${escapeHtml(formatTime(u.last_message_at))}</div>
                                        </div>
                                        <div class="user-status text-truncate">${escapeHtml(u.last_message_preview || '')}</div>
                                    </div>
                                    ${u.unread_count > 0 ? `<span class="badge rounded-pill bg-danger">${u.unread_count}</span>` : ''}
                                </div>
                            `;
                        });
                    }
                    if (!html) {
                        let emptyMsg = 'No chats found.';
                        if (q) emptyMsg = 'No chats match your search.';
                        else if (filter === 'direct') emptyMsg = 'No direct chats found.';
                        else if (filter === 'groups') emptyMsg = 'No group chats found.';
                        html = `<div class="p-3 text-muted">${escapeHtml(emptyMsg)}</div>`;
                    }
                    list.innerHTML = html;
                    list.querySelectorAll('.user-item[data-chat-type]').forEach(el => {
                        el.addEventListener('click', () => {
                            const type = el.getAttribute('data-chat-type');
                            const id = parseInt(el.getAttribute('data-id') || '0');
                            const name = el.getAttribute('data-name') || '';
                            if (!id) return;
                            if (type === 'group') {
                                const members = parseInt(el.getAttribute('data-members') || '0');
                                const role = el.getAttribute('data-role') || 'member';
                                selectGroup(id, name, members, role);
                            } else {
                                const online = (el.getAttribute('data-online') === '1');
                                const pic = el.getAttribute('data-pic') || '';
                                selectDirect(id, name, online, pic);
                            }
                        });
                    });
                })
                .catch(() => {
                    const list = document.getElementById('userList');
                    if (list) list.innerHTML = '<div class="p-3 text-danger">Error loading chats</div>';
                });
        }

        function selectDirect(id, name, online, profilePic) {
            activeChat = { type: 'direct', id };
            peerId = id;
            groupId = null;
            groupRole = 'member';
            document.getElementById('receiverIdInput').value = id;
            document.getElementById('groupIdInput').value = '';
            document.getElementById('activePeerName').textContent = name;
            document.getElementById('activePeerAvatar').innerHTML = avatarHtml(name, profilePic || '');
            document.getElementById('activePeerStatusText').textContent = online ? 'Online' : 'Offline';
            document.getElementById('activePeerStatusDot').className = `status-indicator status-dot-sm position-static ${online ? 'status-online' : 'status-offline'}`;
            document.getElementById('groupMembersBtn').classList.add('d-none');
            document.getElementById('chatInfoBtn').classList.remove('d-none');
            setChatInfoTitle(name || 'Conversation');
            loadChatInfo();
            
            document.getElementById('emptyChat').classList.add('d-none');
            const ci = document.getElementById('chatInterface');
            ci.classList.remove('d-none');
            ci.classList.add('d-flex');
            document.getElementById('chatMessages').innerHTML = '';
            
            lastMessageId = 0;
            fetchMessages();
            loadChatList();
            setMobileChatOpen(true);
        }

        function selectGroup(id, name, memberCount, memberRole) {
            activeChat = { type: 'group', id };
            groupId = id;
            peerId = null;
            groupRole = memberRole || 'member';
            document.getElementById('receiverIdInput').value = '';
            document.getElementById('groupIdInput').value = String(id);
            document.getElementById('activePeerName').textContent = name;
            document.getElementById('activePeerAvatar').innerHTML = groupAvatarHtml(name);
            document.getElementById('activePeerStatusText').textContent = `${memberCount || 0} members`;
            document.getElementById('activePeerStatusDot').className = 'status-indicator status-dot-sm position-static status-offline';
            document.getElementById('groupMembersBtn').classList.remove('d-none');
            document.getElementById('chatInfoBtn').classList.remove('d-none');
            setChatInfoTitle(name || 'Group');
            loadChatInfo();

            document.getElementById('emptyChat').classList.add('d-none');
            const ci = document.getElementById('chatInterface');
            ci.classList.remove('d-none');
            ci.classList.add('d-flex');
            document.getElementById('chatMessages').innerHTML = '';
            lastMessageId = 0;
            fetchMessages();
            loadChatList();
            setMobileChatOpen(true);
        }

        function setChatInfoTitle(title) {
            const el = document.getElementById('chatInfoTitle');
            if (el) el.textContent = title || 'Conversation';
        }

        function setChatInfoPlaceholders(members, files, media) {
            const a = document.getElementById('chatInfoMembersBody');
            const b = document.getElementById('chatInfoFilesBody');
            const c = document.getElementById('chatInfoMediaBody');
            if (a) a.innerHTML = `<div class="text-muted small p-3">${escapeHtml(members || '')}</div>`;
            if (b) b.innerHTML = `<div class="text-muted small p-3">${escapeHtml(files || '')}</div>`;
            if (c) c.innerHTML = `<div class="text-muted small p-3">${escapeHtml(media || '')}</div>`;
        }

        function toggleChatInfo(force) {
            const panel = document.getElementById('chatInfoPanel');
            if (!panel) return;
            if (typeof force === 'boolean') {
                panel.classList.toggle('d-none', !force);
                panel.classList.toggle('d-lg-flex', !!force);
                document.body.classList.toggle('chat-info-open', !!force);
                return;
            }
            const isHidden = panel.classList.contains('d-none');
            panel.classList.toggle('d-none', !isHidden);
            panel.classList.toggle('d-lg-flex', isHidden);
            document.body.classList.toggle('chat-info-open', isHidden);
        }

        function formatFileSize(bytes) {
            const n = parseInt(bytes || 0, 10);
            if (!isFinite(n) || n <= 0) return '—';
            const units = ['B', 'KB', 'MB', 'GB'];
            let val = n;
            let idx = 0;
            while (val >= 1024 && idx < units.length - 1) {
                val /= 1024;
                idx++;
            }
            return `${val >= 10 || idx === 0 ? Math.round(val) : val.toFixed(1)} ${units[idx]}`;
        }

        function formatChatFileType(item) {
            const mime = String(item?.attachment_mime || '').trim();
            if (mime) return mime;
            const name = String(item?.attachment_name || item?.attachment_path || '');
            const ext = name.includes('.') ? name.split('.').pop().toUpperCase() : '';
            return ext || String(item?.message_type || 'File');
        }

        function canPreviewChatFile(item) {
            const kind = String(item?.message_type || '').toLowerCase();
            if (['image', 'video', 'audio'].includes(kind)) return true;
            const name = String(item?.attachment_name || item?.attachment_path || '').toLowerCase();
            return ['.pdf', '.txt', '.csv'].some(ext => name.endsWith(ext));
        }

        function loadChatInfo() {
            if (!activeChat) return;
            const id = parseInt(activeChat.id || 0, 10);
            if (!id) return;
            const membersBody = document.getElementById('chatInfoMembersBody');
            const filesBody = document.getElementById('chatInfoFilesBody');
            const mediaBody = document.getElementById('chatInfoMediaBody');
            if (membersBody) membersBody.innerHTML = '<div class="p-3 text-muted small">Loading...</div>';
            if (filesBody) filesBody.innerHTML = '<div class="p-3 text-muted small">Loading...</div>';
            if (mediaBody) mediaBody.innerHTML = '<div class="p-3 text-muted small">Loading...</div>';

            if (activeChat.type === 'group') {
                fetch(chatUrl(`chat-group-members?group_id=${id}`), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r => r.json())
                    .then(d => {
                        if (!d || !d.ok) { if (membersBody) membersBody.innerHTML = '<div class="p-3 text-muted small">Unable to load members.</div>'; return; }
                        const rows = Array.isArray(d.members) ? d.members : [];
                        if (!rows.length) { if (membersBody) membersBody.innerHTML = '<div class="p-3 text-muted small">No members.</div>'; return; }
                        const html = rows.map(m => `
                            <div class="d-flex align-items-center gap-2 px-3 py-2">
                                <div class="user-avatar" style="width:32px;height:32px">${avatarHtml(m.full_name || 'User', m.profile_pic || '')}</div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold small text-truncate">${escapeHtml(m.full_name || '')}</div>
                                    <div class="text-muted small text-truncate">${escapeHtml(m.user_role || '')}</div>
                                </div>
                                <span class="badge bg-light text-muted border">${escapeHtml(m.role || 'member')}</span>
                            </div>
                        `).join('');
                        if (membersBody) membersBody.innerHTML = html;
                    })
                    .catch(() => { if (membersBody) membersBody.innerHTML = '<div class="p-3 text-muted small">Unable to load members.</div>'; });
            } else if (membersBody) {
                const name = document.getElementById('activePeerName')?.textContent || 'User';
                const avatar = document.getElementById('activePeerAvatar')?.innerHTML || avatarHtml(name, '');
                const status = document.getElementById('activePeerStatusText')?.textContent || '';
                membersBody.innerHTML = `
                    <div class="d-flex align-items-center gap-3 p-3">
                        <div class="user-avatar" style="width:42px;height:42px">${avatar}</div>
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">${escapeHtml(name)}</div>
                            <div class="text-muted small text-truncate">${escapeHtml(status)}</div>
                        </div>
                    </div>
                `;
            }

            const queryKey = activeChat.type === 'group' ? 'group_id' : 'peer_id';
            fetch(chatUrl(`chat-fetch?${queryKey}=${id}&view=files`), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!d || !d.ok) { if (filesBody) filesBody.innerHTML = '<div class="p-3 text-muted small">Unable to load files.</div>'; return; }
                    const items = Array.isArray(d.items) ? d.items : [];
                    if (!items.length) { if (filesBody) filesBody.innerHTML = '<div class="p-3 text-muted small">No files.</div>'; return; }
                    const html = items.map(it => {
                        const name = it.attachment_name || (it.attachment_path || '').split('/').pop();
                        const dl = `../../modules/files/download?kind=chat_attachment&id=${it.id}`;
                        const pv = `${dl}&inline=1`;
                        const previewBtn = canPreviewChatFile(it)
                            ? `<a class="btn btn-sm btn-light border" href="${pv}" target="_blank" title="Preview"><i class="bi bi-eye"></i></a>`
                            : '';
                        return `
                            <div class="px-3 py-2 border-bottom">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="small fw-semibold text-truncate">${escapeHtml(name || '')}</div>
                                        <div class="text-muted small">${escapeHtml(formatChatFileType(it))} · ${escapeHtml(formatFileSize(it.attachment_size))}</div>
                                        <div class="text-muted small">${escapeHtml(it.sender_name || '')} · ${escapeHtml(it.created_at || '')}</div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        ${previewBtn}
                                        <a class="btn btn-sm btn-outline-primary" href="${dl}" target="_blank" download title="Download"><i class="bi bi-download"></i></a>
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('');
                    if (filesBody) filesBody.innerHTML = html;
                })
                .catch(() => { if (filesBody) filesBody.innerHTML = '<div class="p-3 text-muted small">Unable to load files.</div>'; });

            fetch(chatUrl(`chat-fetch?${queryKey}=${id}&view=media`), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!d || !d.ok) { if (mediaBody) mediaBody.innerHTML = '<div class="p-3 text-muted small">Unable to load media.</div>'; return; }
                    const rawItems = Array.isArray(d.items) ? d.items : [];
                    const seen = new Set();
                    const items = rawItems.filter(it => {
                        const key = String(it?.id || '');
                        if (!key || seen.has(key)) return false;
                        seen.add(key);
                        return true;
                    });
                    if (!items.length) { if (mediaBody) mediaBody.innerHTML = '<div class="p-3 text-muted small">No media.</div>'; return; }
                    if (mediaBody) {
                        const html = '<div class="p-3"><div class="row g-2">' + items.map(it => {
                            const pv = `../../modules/files/download?kind=chat_attachment&id=${it.id}&inline=1`;
                            const dl = `../../modules/files/download?kind=chat_attachment&id=${it.id}`;
                            const name = it.attachment_name || (it.attachment_path || '').split('/').pop();
                            return `<div class="col-6">
                                <a class="d-block text-decoration-none" href="${pv}" target="_blank" title="${escapeAttr(name || '')}">
                                    <img src="${pv}" class="img-fluid rounded-3 border" style="height:110px;width:100%;object-fit:cover" alt="${escapeAttr(name || 'Image')}">
                                </a>
                                <div class="small mt-1 text-truncate">${escapeHtml(name || '')}</div>
                                <div class="text-muted small text-truncate">${escapeHtml(it.sender_name || '')} · ${escapeHtml(it.created_at || '')}</div>
                                <a class="small text-decoration-none" href="${dl}" target="_blank" download>Download</a>
                            </div>`;
                        }).join('') + '</div></div>';
                        mediaBody.innerHTML = html || '<div class="p-3 text-muted small">No media.</div>';
                    }
                })
                .catch(() => { if (mediaBody) mediaBody.innerHTML = '<div class="p-3 text-muted small">Unable to load media.</div>'; });

            toggleChatInfo(true);
        }

        function fetchMessages() {
            if (!activeChat) return;
            const url = activeChat.type === 'group'
                ? chatUrl(`chat-fetch?group_id=${activeChat.id}&since_id=${lastMessageId}`)
                : chatUrl(`chat-fetch?peer_id=${activeChat.id}&since_id=${lastMessageId}`);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok || !data.messages.length) return;
                    
                    const box = document.getElementById('chatMessages');
                    const msgs = Array.isArray(data.messages) ? data.messages : [];
                    if (!msgs.length) return;

                    const formatMsgTime = (ts) => {
                        if (!ts) return '';
                        const d = new Date(String(ts).replace(' ', 'T'));
                        if (isNaN(d.getTime())) return String(ts);
                        return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                    };

                    const stickToBottom = (box.scrollHeight - box.scrollTop - box.clientHeight) < 140;
                    const frag = document.createDocumentFragment();
                    for (let i = 0; i < msgs.length; i++) {
                        const m = msgs[i] || {};
                        lastMessageId = Math.max(lastMessageId, parseInt(m.id || 0));
                        const isSystem = m.message_type === 'system';
                        const isOwn = (parseInt(m.sender_id || 0) === currentUserId);
                        const isDeleted = parseInt(m.is_deleted || 0) === 1;
                        const canDelete = !isSystem && (isOwn || (activeChat && activeChat.type === 'group' && groupRole === 'admin'));

                        const prev = i > 0 ? (msgs[i - 1] || {}) : null;
                        const next = i + 1 < msgs.length ? (msgs[i + 1] || {}) : null;
                        const prevSameSender = !!(prev && prev.message_type !== 'system' && !isSystem && parseInt(prev.sender_id || 0) === parseInt(m.sender_id || 0));
                        const nextSameSender = !!(next && next.message_type !== 'system' && !isSystem && parseInt(next.sender_id || 0) === parseInt(m.sender_id || 0));
                        const groupPos = isSystem ? '' : (!prevSameSender && nextSameSender ? 'group-start' : (prevSameSender && nextSameSender ? 'group-mid' : (prevSameSender && !nextSameSender ? 'group-end' : 'group-single')));
                        const showAvatar = !isSystem && (groupPos === 'group-end' || groupPos === 'group-single');

                        const msgDiv = document.createElement('div');
                        msgDiv.className = isSystem ? 'message message-system' : `message ${isOwn ? 'message-own' : 'message-peer'} ${groupPos}${isDeleted ? ' message-deleted' : ''}`;

                        let attachmentHtml = '';
                        if (!isDeleted && m.attachment_path) {
                            const fileName = m.attachment_name || String(m.attachment_path || '').split('/').pop();
                            const kind = (m.message_type || 'file');
                            const dl = `../../modules/files/download?kind=chat_attachment&id=${m.id}`;
                            if (kind === 'image') {
                                attachmentHtml = `
                                    <div class="chat-attachment">
                                        <a href="${dl}" target="_blank" class="text-decoration-none" title="Open">
                                            <img src="../../${escapeAttr(m.attachment_path)}" alt="${escapeAttr(fileName)}" class="img-fluid rounded-3 border" style="max-height:220px;object-fit:cover;">
                                        </a>
                                        <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                                            <div class="text-muted small text-truncate">${escapeHtml(fileName)}</div>
                                            <a href="${dl}" target="_blank" class="btn btn-sm btn-outline-primary" download title="Download"><i class="bi bi-download"></i></a>
                                        </div>
                                    </div>`;
                            } else if (kind === 'video') {
                                attachmentHtml = `
                                    <div class="chat-attachment">
                                        <video class="w-100 rounded-3 border" style="max-height:260px" controls src="../../${escapeAttr(m.attachment_path)}"></video>
                                        <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                                            <div class="text-muted small text-truncate">${escapeHtml(fileName)}</div>
                                            <a href="${dl}" target="_blank" class="btn btn-sm btn-outline-primary" download title="Download"><i class="bi bi-download"></i></a>
                                        </div>
                                    </div>`;
                            } else if (kind === 'audio') {
                                attachmentHtml = `
                                    <div class="chat-attachment">
                                        <audio class="w-100" controls src="../../${escapeAttr(m.attachment_path)}"></audio>
                                        <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                                            <div class="text-muted small text-truncate">${escapeHtml(fileName)}</div>
                                            <a href="${dl}" target="_blank" class="btn btn-sm btn-outline-primary" download title="Download"><i class="bi bi-download"></i></a>
                                        </div>
                                    </div>`;
                            } else {
                                attachmentHtml = `
                                    <div class="chat-attachment">
                                        <div class="d-flex align-items-center justify-content-between gap-2">
                                            <div class="d-flex align-items-center gap-2 text-muted small">
                                                <i class="bi bi-file-earmark-text"></i>
                                                <span class="text-truncate">${escapeHtml(fileName)}</span>
                                            </div>
                                            <a href="${dl}" target="_blank" class="btn btn-sm btn-outline-primary" download title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </div>`;
                            }
                        }

                        const avatar = isSystem
                            ? `<div class="message-avatar message-avatar-spacer"></div>`
                            : `<div class="message-avatar ${showAvatar ? '' : 'message-avatar-spacer'}">${avatarHtml(m.sender_name || (isOwn ? 'You' : 'User'), m.sender_profile_pic || '')}</div>`;

                        const replyBtn = !isSystem && !isDeleted ? `<button type="button" class="btn btn-sm btn-light border" title="Reply" onclick="setReplyTo(${m.id}, '${escapeAttr(m.sender_name || (isOwn ? 'You' : 'User'))}', '${escapeAttr((m.message || '').toString().slice(0,180))}', '${escapeAttr(m.message_type || 'text')}', '${escapeAttr(m.attachment_name || '')}')"><i class="bi bi-reply"></i></button>` : '';
                        const reactBtn = !isSystem && !isDeleted ? `
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-light border dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" title="React">
                                    <i class="bi bi-emoji-smile"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    ${['👍','❤️','🔥','😂','👏','🎯'].map(e => `<li><button type="button" class="dropdown-item" onclick="reactMessage(${m.id}, '${e}')">${e}</button></li>`).join('')}
                                </ul>
                            </div>
                        ` : '';
                        const deleteBtn = canDelete ? `<button type="button" class="btn btn-sm btn-light border message-del-btn" title="Delete" onclick="deleteMessage(${m.id})"><i class="bi bi-trash"></i></button>` : '';
                        const senderLine = (!isSystem && !isOwn && activeChat && activeChat.type === 'group' && m.sender_name && !prevSameSender)
                            ? `<div class="message-sender">${escapeHtml(m.sender_name || '')}</div>`
                            : '';

                        let replyHtml = '';
                        if (!isSystem && !isDeleted && parseInt(m.reply_to_id || 0) > 0) {
                            const rName = m.reply_sender_name || 'User';
                            const rKind = m.reply_attachment_path ? 'Attachment' : (m.reply_message || '');
                            const rTxt = String(rKind || '').trim();
                            replyHtml = `
                                <div class="chat-reply-quote">
                                    <div class="small fw-semibold">${escapeHtml(rName)}</div>
                                    <div class="small text-muted">${escapeHtml(rTxt)}</div>
                                </div>
                            `;
                        }

                        let reactionsHtml = '';
                        const rx = Array.isArray(m.reactions) ? m.reactions : [];
                        if (!isSystem && rx.length) {
                            reactionsHtml = `<div class="chat-reactions">${rx.map(r => `<button type="button" class="chat-reaction-pill ${r.me ? 'active' : ''}" onclick="reactMessage(${m.id}, '${escapeAttr(r.reaction || '')}')">${escapeHtml(r.reaction || '')} <span class="chat-reaction-count">${parseInt(r.count || 0)}</span></button>`).join('')}</div>`;
                        }

                        const msgText = isDeleted ? 'This message was deleted' : (m.message || '');
                        const msgTextHtml = msgText ? `<div class="message-text">${escapeHtml(String(msgText))}</div>` : '';
                        const timeHtml = `<div class="message-meta"><span class="message-time">${escapeHtml(formatMsgTime(m.created_at || ''))}</span>${isOwn && activeChat.type === 'direct' ? `<i class="bi bi-check2-all ${m.read_at ? 'text-primary' : ''}"></i>` : ''}</div>`;
                        const actionsHtml = (!isSystem && (replyBtn || reactBtn || deleteBtn)) ? `<div class="message-actions">${replyBtn}${reactBtn}${deleteBtn}</div>` : '';

                        msgDiv.innerHTML = `
                            ${isSystem ? `<div class="message-system-text">${escapeHtml(m.message || '')}</div>` : `
                                ${avatar}
                                <div class="message-bubble">
                                    ${senderLine}
                                    ${actionsHtml}
                                    <div class="message-content">
                                        ${replyHtml}
                                        ${msgTextHtml}
                                        ${attachmentHtml}
                                        ${timeHtml}
                                    </div>
                                    ${reactionsHtml}
                                </div>
                            `}
                        `;
                        frag.appendChild(msgDiv);
                    }
                    box.appendChild(frag);
                    if (stickToBottom) {
                        try { box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' }); } catch (e) { box.scrollTop = box.scrollHeight; }
                    }
                    loadChatList();
                })
                .catch(() => {});
        }

        document.getElementById('chatForm').onsubmit = function(e) {
            e.preventDefault();
            if (!activeChat) return;
            
            const msgInput = document.getElementById('messageInput');
            if (!msgInput.value.trim() && !document.getElementById('attachmentInput').files.length) return;

            const fd = new FormData(this);
            fetch(chatUrl('chat-send'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    msgInput.value = '';
                    clearAttachment();
                    clearReplyTo();
                    fetchMessages();
                }
            }).catch(() => {});
        };

        document.getElementById('attachmentInput').onchange = function() {
            if (this.files.length) {
                document.getElementById('fileName').textContent = this.files[0].name;
                document.getElementById('attachmentPreview').classList.remove('d-none');
            }
        };

        function clearAttachment() {
            document.getElementById('attachmentInput').value = '';
            document.getElementById('attachmentPreview').classList.add('d-none');
        }

        function heartbeat() {
            fetch(chatUrl('chat-presence'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf_token: csrfToken, online: true })
            }).catch(() => {});
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeAttr(text) {
            return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function deleteMessage(id) {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('message_id', String(id));
            fetch(chatUrl('chat-delete'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok) {
                        lastMessageId = 0;
                        document.getElementById('chatMessages').innerHTML = '';
                        fetchMessages();
                    }
                })
                .catch(() => {});
        }

        function setReplyTo(messageId, senderName, text, kind, attachmentName) {
            const id = parseInt(messageId || 0);
            if (!id) return;
            const title = senderName ? ('Reply to ' + senderName) : 'Reply';
            let preview = (text || '').trim();
            if (!preview && kind && kind !== 'text') preview = attachmentName ? ('Attachment: ' + attachmentName) : 'Attachment';
            const input = document.getElementById('replyToIdInput');
            if (input) input.value = String(id);
            const wrap = document.getElementById('replyPreview');
            const t = document.getElementById('replyPreviewTitle');
            const p = document.getElementById('replyPreviewText');
            if (t) t.textContent = title;
            if (p) p.textContent = preview || '—';
            if (wrap) wrap.classList.remove('d-none');
            document.getElementById('messageInput')?.focus();
        }

        function clearReplyTo() {
            const input = document.getElementById('replyToIdInput');
            if (input) input.value = '0';
            const wrap = document.getElementById('replyPreview');
            if (wrap) wrap.classList.add('d-none');
        }

        function reactMessage(messageId, emoji) {
            if (!activeChat) return;
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'react');
            fd.append('message_id', String(messageId || '0'));
            fd.append('reaction', String(emoji || ''));
            fetch(chatUrl('chat-send'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok) {
                        lastMessageId = 0;
                        document.getElementById('chatMessages').innerHTML = '';
                        fetchMessages();
                    }
                })
                .catch(() => {});
        }

        function loadGroupMemberPickList() {
            fetch(chatUrl('chat-list-users'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!d || !d.ok) return;
                    allUsersForGroupPick = (d.users || []).filter(u => u.id !== currentUserId);
                    renderGroupMemberPickList();
                })
                .catch(() => {});
        }

        function renderGroupMemberPickList() {
            const q = (document.getElementById('groupMemberSearch')?.value || '').trim().toLowerCase();
            const box = document.getElementById('groupMemberPickList');
            if (!box) return;
            const items = allUsersForGroupPick.filter(u => {
                if (!q) return true;
                const hay = [u.full_name, u.username, u.email, u.role].map(v => String(v || '').toLowerCase());
                return hay.some(v => v.includes(q));
            });
            box.innerHTML = items.map(u => `
                <label class="list-group-item d-flex align-items-center gap-2">
                    <input class="form-check-input me-1" type="checkbox" value="${u.id}">
                    <span class="flex-grow-1">${escapeHtml(u.full_name || '')}</span>
                    <span class="badge bg-light text-muted border">${escapeHtml(u.role || '')}</span>
                </label>
            `).join('') || '<div class="text-muted small p-2">No users</div>';
        }

        function createGroup() {
            const name = (document.getElementById('newGroupName')?.value || '').trim();
            if (!name) return;
            const checked = Array.from(document.querySelectorAll('#groupMemberPickList input[type="checkbox"]:checked')).map(el => parseInt(el.value || '0')).filter(n => n > 0);
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'create');
            fd.append('group_name', name);
            checked.forEach(id => fd.append('member_ids[]', String(id)));
            fetch(chatUrl('chat-groups'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok && d.group) {
                        const modalEl = document.getElementById('createGroupModal');
                        if (modalEl && window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                        document.getElementById('newGroupName').value = '';
                        document.querySelectorAll('#groupMemberPickList input[type="checkbox"]').forEach(el => el.checked = false);
                        loadChatList();
                        selectGroup(d.group.id, d.group.group_name, 0, 'admin');
                    }
                })
                .catch(() => {});
        }

        function loadGroupMembers() {
            if (!activeChat || activeChat.type !== 'group') return;
            const body = document.getElementById('groupMembersBody');
            if (body) body.textContent = 'Loading...';
            fetch(chatUrl(`chat-group-members?group_id=${activeChat.id}`), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(d => {
                    if (!body) return;
                    if (!d || !d.ok) { body.textContent = d && d.error ? d.error : 'Failed'; return; }
                    const members = Array.isArray(d.members) ? d.members : [];
                    const myRole = d.my_role || 'member';
                    const isAdmin = myRole === 'admin';
                    const memberIds = new Set(members.map(m => parseInt(m.user_id || 0)).filter(n => n > 0));
                    if (!allUsersForGroupPick.length) {
                        fetch(chatUrl('chat-list-users'), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(r => r.json())
                            .then(u => { if (u && u.ok) allUsersForGroupPick = (u.users || []).filter(x => x.id !== currentUserId); })
                            .catch(()=>{});
                    }
                    const addable = allUsersForGroupPick.filter(u => !memberIds.has(u.id));
                    let html = '';
                    html += `<div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="text-muted small">${members.length} members</div>
                        <div class="badge bg-light text-muted border">${escapeHtml(myRole)}</div>
                    </div>`;
                    if (isAdmin) {
                        html += `
                            <div class="border rounded p-2 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <select class="form-select form-select-sm" id="addMemberSelect">
                                        <option value="">Add member...</option>
                                        ${addable.map(u => `<option value="${u.id}">${escapeHtml(u.full_name || '')} (${escapeHtml(u.role || '')})</option>`).join('')}
                                    </select>
                                    <button class="btn btn-sm btn-primary" type="button" data-action="add-member"><i class="bi bi-person-plus"></i></button>
                                </div>
                                <div class="text-muted small mt-2">Admins can add/remove members and assign admins.</div>
                            </div>
                        `;
                    }
                    html += `<div class="list-group">` + members.map(m => `
                        <div class="list-group-item d-flex align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:32px;height:32px;border-radius:999px;overflow:hidden;background:#e5e7eb;" class="d-flex align-items-center justify-content-center">
                                    ${avatarHtml(m.full_name || 'U', m.profile_pic || '')}
                                </div>
                                <div>
                                    <div class="fw-semibold">${escapeHtml(m.full_name || '')}</div>
                                    <div class="text-muted small">${escapeHtml(m.role || '')}</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                ${(m.role === 'admin') ? '<span class="badge bg-primary-subtle text-primary border">Admin</span>' : '<span class="badge bg-light text-muted border">Member</span>'}
                                ${isAdmin && (parseInt(m.user_id || 0) !== currentUserId) ? `
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-light border" type="button" data-action="toggle-role" data-user-id="${m.user_id}" data-next-role="${m.role === 'admin' ? 'member' : 'admin'}" title="${m.role === 'admin' ? 'Demote to member' : 'Make admin'}">
                                            <i class="bi ${m.role === 'admin' ? 'bi-person' : 'bi-person-check'}"></i>
                                        </button>
                                        <button class="btn btn-light border text-danger" type="button" data-action="remove-member" data-user-id="${m.user_id}" title="Remove">
                                            <i class="bi bi-person-dash"></i>
                                        </button>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `).join('') + `</div>`;
                    body.innerHTML = html;

                    if (isAdmin) {
                        body.querySelector('[data-action="add-member"]')?.addEventListener('click', () => {
                            const sel = body.querySelector('#addMemberSelect');
                            const uid2 = parseInt(sel?.value || '0');
                            if (!uid2) return;
                            const fd = new FormData();
                            fd.append('csrf_token', csrfToken);
                            fd.append('group_id', String(activeChat.id));
                            fd.append('action', 'add');
                            fd.append('user_id', String(uid2));
                            fetch(chatUrl('chat-group-members'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                .then(r => r.json())
                                .then(res => { if (res && res.ok) loadGroupMembers(); });
                        });

                        body.querySelectorAll('[data-action="remove-member"]').forEach(btn => {
                            btn.addEventListener('click', () => {
                                const uid2 = parseInt(btn.getAttribute('data-user-id') || '0');
                                if (!uid2) return;
                                if (!confirm('Remove this user from group?')) return;
                                const fd = new FormData();
                                fd.append('csrf_token', csrfToken);
                                fd.append('group_id', String(activeChat.id));
                                fd.append('action', 'remove');
                                fd.append('user_id', String(uid2));
                                fetch(chatUrl('chat-group-members'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                    .then(r => r.json())
                                    .then(res => { if (res && res.ok) loadGroupMembers(); });
                            });
                        });

                        body.querySelectorAll('[data-action="toggle-role"]').forEach(btn => {
                            btn.addEventListener('click', () => {
                                const uid2 = parseInt(btn.getAttribute('data-user-id') || '0');
                                const role2 = btn.getAttribute('data-next-role') || 'member';
                                if (!uid2) return;
                                const fd = new FormData();
                                fd.append('csrf_token', csrfToken);
                                fd.append('group_id', String(activeChat.id));
                                fd.append('action', 'set_role');
                                fd.append('user_id', String(uid2));
                                fd.append('role', role2);
                                fetch(chatUrl('chat-group-members'), { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                                    .then(r => r.json())
                                    .then(res => { if (res && res.ok) loadGroupMembers(); });
                            });
                        });
                    }
                })
                .catch(() => { if (body) body.textContent = 'Failed to load members.'; });
        }

        document.getElementById('groupMembersModal').addEventListener('show.bs.modal', loadGroupMembers);

        const chatSearchEl = document.getElementById('chatSearch');
        if (chatSearchEl) {
            chatSearchEl.addEventListener('input', () => {
                clearTimeout(window.__chatSearchT);
                window.__chatSearchT = setTimeout(loadChatList, 250);
            });
            chatSearchEl.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    chatSearchEl.value = '';
                    loadChatList();
                }
            });
        }
        const chatFilterMenu = document.getElementById('chatFilterMenu');
        if (chatFilterMenu) {
            chatFilterMenu.addEventListener('click', (e) => {
                const item = e.target.closest('[data-filter]');
                if (!item) return;
                e.preventDefault();
                setChatFilter(item.getAttribute('data-filter') || 'all', item);
            });
        }
        const chatFilterBtn = document.getElementById('chatFilterBtn');
        if (chatFilterBtn && window.bootstrap && bootstrap.Dropdown) {
            bootstrap.Dropdown.getOrCreateInstance(chatFilterBtn, {
                popperConfig(defaultBsPopperConfig) {
                    return { ...defaultBsPopperConfig, strategy: 'fixed' };
                }
            });
        }
        document.getElementById('groupMemberSearch')?.addEventListener('input', renderGroupMemberPickList);
        document.getElementById('createGroupModal').addEventListener('show.bs.modal', loadGroupMemberPickList);
        document.getElementById('createGroupModal')?.addEventListener('hidden.bs.modal', () => {
            const gs = document.getElementById('groupMemberSearch');
            if (gs) gs.value = '';
            renderGroupMemberPickList();
        });

        // Initialize
        setInterval(loadChatList, 5000);
        setInterval(fetchMessages, 3000);
        setInterval(heartbeat, 15000);
        loadChatList();
        heartbeat();
        setMobileChatOpen(false);
        window.addEventListener('resize', () => {
            if (!isMobileChatUi()) { setMobileChatOpen(false); return; }
            setMobileChatOpen(!!activeChat);
        });

        (function initFromUrl(){
            const params = new URLSearchParams(window.location.search);
            const u = parseInt(params.get('user_id') || '0');
            const g = parseInt(params.get('group_id') || '0');
            if (g > 0) {
                selectGroup(g, 'Group', 0, 'member');
                return;
            }
            if (u > 0) {
                selectDirect(u, 'User', false, '');
            }
        })();

        // Emoji Picker Logic
        function toggleEmojiPicker() {
            const p = document.getElementById('emojiPicker');
            if (p) {
                const isOpening = p.classList.contains('d-none');
                p.classList.toggle('d-none');
                if (isOpening) renderRecentEmojis();
            }
        }

        function insertEmoji(e) {
            const input = document.getElementById('messageInput');
            if (input) {
                const start = input.selectionStart;
                const end = input.selectionEnd;
                const text = input.value;
                input.value = text.substring(0, start) + e + text.substring(end);
                input.focus();
                const newPos = start + e.length;
                input.setSelectionRange(newPos, newPos);
                saveRecentEmoji(e);
            }
            toggleEmojiPicker();
        }

        function saveRecentEmoji(e) {
            let recent = JSON.parse(localStorage.getItem('chat_recent_emojis') || '[]');
            recent = recent.filter(x => x !== e);
            recent.unshift(e);
            recent = recent.slice(0, 8); // Keep top 8
            localStorage.setItem('chat_recent_emojis', JSON.stringify(recent));
        }

        function renderRecentEmojis() {
            const container = document.getElementById('recentEmojisContainer');
            const list = document.getElementById('recentEmojiList');
            const recent = JSON.parse(localStorage.getItem('chat_recent_emojis') || '[]');
            
            if (recent.length > 0) {
                container.classList.remove('d-none');
                list.innerHTML = recent.map(e => `<button type="button" class="btn btn-sm btn-light p-1 border-0 emoji-item" onclick="insertEmoji('${e}')">${e}</button>`).join('');
            } else {
                container.classList.add('d-none');
            }
        }

        const pickerBtn = document.getElementById('emojiPickerBtn');
        if (pickerBtn) {
            pickerBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleEmojiPicker();
            });
        }

        // Close picker when clicking outside
        document.addEventListener('click', (e) => {
            const p = document.getElementById('emojiPicker');
            if (p && !p.classList.contains('d-none') && !p.contains(e.target)) {
                p.classList.add('d-none');
            }
        });
    </script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
