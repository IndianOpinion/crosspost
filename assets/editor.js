/**
 * Crosspost panel for the block editor. Plain script, no build step.
 * Shows, per network, the exact text that would be sent, its count against
 * the limit, the image and alt text, the job state, and a "post now" button.
 */
(function (wp) {
	'use strict';
	if (!wp || !wp.plugins || !wp.element || !wp.apiFetch) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var components = wp.components;
	var useSelect = wp.data.useSelect;
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	if (!Panel) {
		return;
	}

	function NetworkBox(props) {
		var item = props.item;
		var preview = item.preview;
		var status = item.status;
		var children = [el('h3', { key: 'h' }, item.label)];
		if (status) {
			children.push(el('p', { key: 's' }, status.state + (status.reason ? ': ' + status.reason : '')));
			if (status.state === 'posted') {
				if (status.remote_url) {
					children.push(el('p', { key: 'l' }, el('a', { href: status.remote_url, target: '_blank', rel: 'noopener noreferrer' }, __('View the post', 'crosspost'))));
				}
				children.push(el('p', { key: 'n' }, sprintf(__('Already posted on %s; this plugin never removes remote posts.', 'crosspost'), item.label)));
				return el('div', { className: 'crosspost-network' }, children);
			}
		}
		if (preview) {
			children.push(el('pre', { key: 't', style: { whiteSpace: 'pre-wrap', fontSize: '12px' } }, preview.text));
			children.push(el('p', { key: 'c' }, sprintf(__('%1$d of %2$d', 'crosspost'), preview.count, preview.limit)));
			if (preview.error) {
				children.push(el('p', { key: 'e' }, el('strong', null, preview.error)));
			}
			(preview.warnings || []).forEach(function (w, i) {
				children.push(el('p', { key: 'w' + i }, w));
			});
			children.push(el('p', { key: 'i' }, (preview.image ? sprintf(__('Image %1$d, alt: %2$s', 'crosspost'), preview.image, preview.alt) : __('No image', 'crosspost')) + ' ' + (preview.image_note || '')));
		}
		var canSend = props.canPostNow && props.published && item.enabled && (!status || status.state === 'cancelled' || status.state === 'skipped' || (status.state === 'failed' && status.reason !== 'never landed'));
		if (canSend) {
			children.push(el(components.Button, { key: 'b', variant: 'secondary', onClick: function () { props.onPostNow(item.network); } }, __('Post now', 'crosspost')));
		}
		return el('div', { className: 'crosspost-network' }, children);
	}

	function CrosspostPanel() {
		var post = useSelect(function (select) {
			var editor = select('core/editor');
			var meta = editor.getEditedPostAttribute('meta') || {};
			return { id: editor.getCurrentPostId(), status: editor.getEditedPostAttribute('status'), image: meta._crosspost_image || 0 };
		}, []);
		var state = useState(null);
		var data = state[0];
		var setData = state[1];
		var msg = useState('');
		var message = msg[0];
		var setMessage = msg[1];

		function load() {
			if (!post.id) {
				return;
			}
			wp.apiFetch({ path: '/crosspost/v1/posts/' + post.id }).then(setData).catch(function (e) {
				setMessage(e && e.message ? e.message : __('Could not load the preview.', 'crosspost'));
			});
		}
		useEffect(load, [post.id]);

		if (!data) {
			return el(Panel, { name: 'crosspost', title: __('Crosspost', 'crosspost') }, el('p', null, message || __('Loading...', 'crosspost')));
		}
		var children = [
			el(components.CheckboxControl, {
				key: 'opt',
				label: __('Do not crosspost', 'crosspost'),
				checked: !!data.opt_out,
				onChange: function (value) {
					wp.apiFetch({ path: '/crosspost/v1/posts/' + post.id + '/opt-out', method: 'POST', data: { opt_out: value } }).then(load);
				}
			}),
			el(components.TextControl, {
				key: 'img',
				type: 'number',
				label: __('Image override (attachment id, saved with the post)', 'crosspost'),
				value: post.image ? String(post.image) : '',
				onChange: function (value) {
					var id = parseInt(value, 10);
					wp.data.dispatch('core/editor').editPost({ meta: { _crosspost_image: id > 0 ? id : 0 } });
				}
			}),
			el(components.Button, { key: 'r', variant: 'link', onClick: load }, __('Refresh preview', 'crosspost'))
		];
		if (message) {
			children.push(el('p', { key: 'm' }, message));
		}
		(data.networks || []).forEach(function (item) {
			children.push(el(NetworkBox, {
				key: item.network,
				item: item,
				canPostNow: data.can_post_now,
				published: post.status === 'publish',
				onPostNow: function (network) {
					wp.apiFetch({ path: '/crosspost/v1/posts/' + post.id + '/send', method: 'POST', data: { network: network } })
						.then(function () { setMessage(__('Queued.', 'crosspost')); load(); })
						.catch(function (e) { setMessage(e && e.message ? e.message : __('Not queued.', 'crosspost')); });
				}
			}));
		});
		return el(Panel, { name: 'crosspost', title: __('Crosspost', 'crosspost') }, children);
	}

	wp.plugins.registerPlugin('crosspost', { render: CrosspostPanel, icon: 'share' });
})(window.wp);
