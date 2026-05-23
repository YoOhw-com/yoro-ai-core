(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml,
			formatDateTime: helpers.formatDateTime,
			renderDebugJson: helpers.renderDebugJson,
			shouldShowDebugJson: helpers.shouldShowDebugJson,
			renderSuggestions: helpers.renderSuggestions,
			getResultSuggestions: helpers.getResultSuggestions,
			getConversationState: helpers.getConversationState,
			addAssistantMessage: helpers.addAssistantMessage,
			addLoadingMessage: helpers.addLoadingMessage,
			updateConversationState: helpers.updateConversationState,
			openModal: helpers.openModal,
			openTextModal: helpers.openTextModal,
			openNoteModal: helpers.openNoteModal,
			openStatusModal: helpers.openStatusModal,
			openStatusNoteModal: helpers.openStatusNoteModal,
			executeActionDirect: helpers.executeActionDirect,
			setCurrentOrderId: helpers.setCurrentOrderId,
			getSelectedOrderIds: helpers.getSelectedOrderIds
		};
	}

	function init(helpers) {
		const h = getHelpers(helpers);

		return {
			renderOrderDetailsResult: function(data) {
				return renderOrderDetailsResult(data, h);
			},
			renderOrdersResult: function(data) {
				return renderOrdersResult(data, h);
			},
			renderCustomerSummaryResult: function(data) {
				return renderCustomerSummaryResult(data, h);
			},
			renderOperationsSummaryResult: function(data) {
				return renderOperationsSummaryResult(data, h);
			},
			renderBusinessSummaryResult: function(data) {
				return renderBusinessSummaryResult(data, h);
			},
			renderMorningBriefingResult: function(data) {
				return renderMorningBriefingResult(data, h);
			},
			renderDailyBriefingResult: function(data) {
				return renderDailyBriefingResult(data, h);
			},
			renderOperationsAttentionQueueResult: function(data) {
				return renderOperationsAttentionQueueResult(data, h);
			},
			renderFulfillmentQueueResult: function(data) {
				return renderFulfillmentQueueResult(data, h);
			},
			renderInventoryQueueResult: function(data) {
				return renderInventoryQueueResult(data, h);
			},
			renderStoreInsightsResult: function(data) {
				return renderStoreInsightsResult(data, h);
			},
			renderCustomerServiceQueueResult: function(data) {
				return renderCustomerServiceQueueResult(data, h);
			},
			renderRefundPreviewResult: function(data) {
				return renderRefundPreviewResult(data, h);
			},
			handleClick: function(event) {
				return handleOrderClick(event, h);
			}
		};
	}

	function formatDateTime(value, timestamp, h) {
		if (typeof h.formatDateTime === 'function') {
			return h.formatDateTime(timestamp, value);
		}

		return String(value || '');
	}

	function formatAgeHours(value) {
		const hours = parseInt(value || 0, 10) || 0;

		if (hours <= 0) {
			return '<1h';
		}

		if (hours < 24) {
			return hours + 'h';
		}

		const days = Math.floor(hours / 24);
		const remainingHours = hours % 24;

		if (days < 7) {
			return remainingHours ? (days + 'd ' + remainingHours + 'h') : (days + 'd');
		}

		const weeks = Math.floor(days / 7);
		const remainingDays = days % 7;

		if (weeks < 5) {
			return remainingDays ? (weeks + 'w ' + remainingDays + 'd') : (weeks + 'w');
		}

		const months = Math.floor(days / 30);
		const remainingMonthDays = days % 30;

		if (months > 0) {
			return remainingMonthDays ? (months + 'mo ' + remainingMonthDays + 'd') : (months + 'mo');
		}

		return days + 'd';
	}

	function formatAgeBadge(value) {
		const text = String(value || '');
		const match = text.match(/^(\d+)\s*h$/i);

		if (match) {
			return formatAgeHours(match[1]);
		}

		return text;
	}

		function getResultLocale(data) {
			data = data || {};
			const parsed = data.parsed || {};
			const result = data.result || {};
			const decision = parsed.command_decision || {};
			const language = String(parsed.language || decision.language || result.language || '').toLowerCase();
			const locale = String(parsed.locale || result.locale || '').toLowerCase();
			const detected = normalizeLocale(language || locale);

			return detected || 'en';
		}

		function isVietnamese(locale) {
			return String(locale || '').toLowerCase().indexOf('vi') === 0;
		}

		function normalizeLocale(locale) {
			return String(locale || '').toLowerCase().split(/[_-]/)[0] || '';
		}

		function orderUiText(locale, key, fallback) {
			locale = normalizeLocale(locale);
			const labelsByLocale = {
				vi: {
					orders: 'Đơn hàng',
					needsAttention: 'Cần chú ý',
					needsAttentionSubtle: 'Đang chờ xử lý, tạm giữ hoặc thất bại',
					combinedTotal: 'Tổng cộng',
					combinedTotalSubtle: 'Trong các kết quả đang hiển thị',
					averageAge: 'Tuổi trung bình',
					visibleResults: 'Kết quả đang hiển thị',
					hold: 'Tạm giữ',
					viewDetails: 'Xem chi tiết',
					addNote: 'Thêm ghi chú',
					updateStatus: 'Cập nhật trạng thái',
					statusNote: 'Trạng thái + ghi chú',
					order: 'Đơn',
					customer: 'Khách hàng',
					case: 'Trường hợp',
					total: 'Tổng',
					email: 'Email',
					date: 'Ngày',
					age: 'Tuổi',
					nextAction: 'Hành động tiếp theo',
					actions: 'Hành động',
					open: 'Mở',
					compareDetails: 'So sánh chi tiết',
					showFirstOrder: 'Xem đơn đầu tiên',
					customerSummary: 'Tóm tắt khách hàng',
					showSourceOrder: 'Xem đơn gốc',
					latestFiveOrders: '5 đơn gần nhất',
					whatNext: 'Tiếp theo nên làm gì',
					noVisibleOrders: 'Không có đơn hiển thị',
					tryWiderFilters: 'Thử mở rộng bộ lọc hoặc xem danh sách đơn gần đây rộng hơn.',
					tryNext: 'Thử tiếp',
					decisionRoute: 'Tuyến quyết định',
					confidence: 'Độ tin cậy',
					orderReview: 'Cần xem lại đơn hàng',
					customerFallback: 'Khách hàng'
				},
				es: {
					orders: 'Pedidos',
					recentMatching: 'Pedidos coincidentes recientes',
					recentMatchingSubtle: 'Los pedidos coincidentes recientes se muestran abajo.',
					needsAttention: 'Necesitan atención',
					needsAttentionSubtle: 'Pendientes, en espera o fallidos',
					combinedTotal: 'Total combinado',
					combinedTotalSubtle: 'En los resultados visibles',
					averageAge: 'Antigüedad media',
					visibleResults: 'Resultados visibles',
					hold: 'Poner en espera',
					viewDetails: 'Ver detalles',
					addNote: 'Añadir nota',
					updateStatus: 'Actualizar estado',
					statusNote: 'Estado + nota',
					order: 'Pedido',
					customer: 'Cliente',
					case: 'Caso',
					total: 'Total',
					email: 'Correo',
					date: 'Fecha',
					age: 'Antigüedad',
					nextAction: 'Siguiente acción',
					actions: 'Acciones',
					open: 'Abrir',
					showFirstOrder: 'Ver primer pedido',
					latestFiveOrders: 'Últimos 5 pedidos',
					whatNext: 'Qué hacer ahora',
					decisionRoute: 'Ruta de decisión',
					confidence: 'Confianza',
					orderReview: 'Revisión del pedido',
					customerFallback: 'Cliente'
				},
				fr: {
					orders: 'Commandes',
					recentMatching: 'Commandes correspondantes récentes',
					recentMatchingSubtle: 'Les commandes correspondantes récentes sont listées ci-dessous.',
					needsAttention: 'À traiter',
					needsAttentionSubtle: 'En attente, en pause ou échouées',
					combinedTotal: 'Total combiné',
					combinedTotalSubtle: 'Dans les résultats visibles',
					averageAge: 'Âge moyen',
					visibleResults: 'Résultats visibles',
					hold: 'Mettre en pause',
					viewDetails: 'Voir les détails',
					addNote: 'Ajouter une note',
					updateStatus: 'Mettre à jour le statut',
					statusNote: 'Statut + note',
					order: 'Commande',
					customer: 'Client',
					case: 'Cas',
					total: 'Total',
					email: 'E-mail',
					date: 'Date',
					age: 'Âge',
					nextAction: 'Action suivante',
					actions: 'Actions',
					open: 'Ouvrir',
					showFirstOrder: 'Voir la première commande',
					latestFiveOrders: '5 dernières commandes',
					whatNext: 'Que faire ensuite',
					decisionRoute: 'Route de décision',
					confidence: 'Confiance',
					orderReview: 'Revue de commande'
				},
				de: {
					orders: 'Bestellungen',
					recentMatching: 'Aktuelle passende Bestellungen',
					recentMatchingSubtle: 'Die aktuell passenden Bestellungen sind unten aufgeführt.',
					needsAttention: 'Benötigt Aufmerksamkeit',
					needsAttentionSubtle: 'Ausstehend, zurückgestellt oder fehlgeschlagen',
					combinedTotal: 'Gesamtsumme',
					combinedTotalSubtle: 'In den sichtbaren Ergebnissen',
					averageAge: 'Durchschnittsalter',
					visibleResults: 'Sichtbare Ergebnisse',
					hold: 'Zurückstellen',
					viewDetails: 'Details ansehen',
					addNote: 'Notiz hinzufügen',
					updateStatus: 'Status aktualisieren',
					statusNote: 'Status + Notiz',
					order: 'Bestellung',
					customer: 'Kunde',
					case: 'Fall',
					total: 'Summe',
					email: 'E-Mail',
					date: 'Datum',
					age: 'Alter',
					nextAction: 'Nächste Aktion',
					actions: 'Aktionen',
					open: 'Öffnen',
					showFirstOrder: 'Erste Bestellung anzeigen',
					latestFiveOrders: 'Letzte 5 Bestellungen',
					whatNext: 'Nächste Schritte',
					decisionRoute: 'Entscheidungsroute',
					confidence: 'Vertrauen',
					orderReview: 'Bestellprüfung'
				},
				ja: {
					orders: '注文',
					recentMatching: '最近一致した注文',
					recentMatchingSubtle: '最近一致した注文を下に表示します。',
					needsAttention: '要対応',
					needsAttentionSubtle: '保留中、保留、または失敗',
					combinedTotal: '合計',
					combinedTotalSubtle: '表示中の結果',
					averageAge: '平均経過時間',
					visibleResults: '表示中の結果',
					hold: '保留にする',
					viewDetails: '詳細を見る',
					addNote: 'メモを追加',
					updateStatus: 'ステータス更新',
					statusNote: 'ステータス + メモ',
					order: '注文',
					customer: '顧客',
					case: '内容',
					total: '合計',
					email: 'メール',
					date: '日付',
					age: '経過時間',
					nextAction: '次の操作',
					actions: '操作',
					open: '開く',
					showFirstOrder: '最初の注文を見る',
					latestFiveOrders: '最新5件の注文',
					whatNext: '次にできること',
					decisionRoute: '判定ルート',
					confidence: '信頼度',
					orderReview: '注文確認'
				},
				th: {
					orders: 'คำสั่งซื้อ',
					recentMatching: 'คำสั่งซื้อที่ตรงกันล่าสุด',
					recentMatchingSubtle: 'คำสั่งซื้อที่ตรงกันล่าสุดแสดงอยู่ด้านล่าง',
					needsAttention: 'ต้องตรวจสอบ',
					needsAttentionSubtle: 'รอดำเนินการ พักไว้ หรือล้มเหลว',
					combinedTotal: 'ยอดรวม',
					combinedTotalSubtle: 'ในผลลัพธ์ที่แสดง',
					averageAge: 'อายุเฉลี่ย',
					visibleResults: 'ผลลัพธ์ที่แสดง',
					hold: 'พักไว้',
					viewDetails: 'ดูรายละเอียด',
					addNote: 'เพิ่มบันทึก',
					updateStatus: 'อัปเดตสถานะ',
					statusNote: 'สถานะ + บันทึก',
					order: 'คำสั่งซื้อ',
					customer: 'ลูกค้า',
					case: 'กรณี',
					total: 'ยอดรวม',
					email: 'อีเมล',
					date: 'วันที่',
					age: 'อายุ',
					nextAction: 'การดำเนินการถัดไป',
					actions: 'การดำเนินการ',
					open: 'เปิด',
					showFirstOrder: 'ดูคำสั่งซื้อแรก',
					latestFiveOrders: '5 คำสั่งซื้อล่าสุด',
					whatNext: 'ขั้นตอนถัดไป',
					decisionRoute: 'เส้นทางการตัดสินใจ',
					confidence: 'ความมั่นใจ',
					orderReview: 'ตรวจสอบคำสั่งซื้อ'
				},
				id: {
					orders: 'Pesanan',
					recentMatching: 'Pesanan terbaru yang cocok',
					recentMatchingSubtle: 'Pesanan terbaru yang cocok ditampilkan di bawah.',
					needsAttention: 'Perlu perhatian',
					needsAttentionSubtle: 'Tertunda, ditahan, atau gagal',
					combinedTotal: 'Total gabungan',
					combinedTotalSubtle: 'Dalam hasil yang terlihat',
					averageAge: 'Usia rata-rata',
					visibleResults: 'Hasil yang terlihat',
					hold: 'Tahan',
					viewDetails: 'Lihat detail',
					addNote: 'Tambah catatan',
					updateStatus: 'Perbarui status',
					statusNote: 'Status + catatan',
					order: 'Pesanan',
					customer: 'Pelanggan',
					case: 'Kasus',
					total: 'Total',
					email: 'Email',
					date: 'Tanggal',
					age: 'Usia',
					nextAction: 'Tindakan berikutnya',
					actions: 'Tindakan',
					open: 'Buka',
					showFirstOrder: 'Lihat pesanan pertama',
					latestFiveOrders: '5 pesanan terbaru',
					whatNext: 'Langkah berikutnya',
					decisionRoute: 'Rute keputusan',
					confidence: 'Keyakinan',
					orderReview: 'Tinjauan pesanan'
				},
				pt: {
					orders: 'Pedidos',
					recentMatching: 'Pedidos correspondentes recentes',
					recentMatchingSubtle: 'Os pedidos correspondentes recentes aparecem abaixo.',
					needsAttention: 'Precisam de atenção',
					needsAttentionSubtle: 'Pendentes, em espera ou falhados',
					combinedTotal: 'Total combinado',
					combinedTotalSubtle: 'Nos resultados visíveis',
					averageAge: 'Idade média',
					visibleResults: 'Resultados visíveis',
					hold: 'Colocar em espera',
					viewDetails: 'Ver detalhes',
					addNote: 'Adicionar nota',
					updateStatus: 'Atualizar estado',
					statusNote: 'Estado + nota',
					order: 'Pedido',
					customer: 'Cliente',
					case: 'Caso',
					total: 'Total',
					email: 'E-mail',
					date: 'Data',
					age: 'Idade',
					nextAction: 'Próxima ação',
					actions: 'Ações',
					open: 'Abrir',
					showFirstOrder: 'Ver primeiro pedido',
					latestFiveOrders: 'Últimos 5 pedidos',
					whatNext: 'Próximos passos',
					decisionRoute: 'Rota de decisão',
					confidence: 'Confiança',
					orderReview: 'Revisão do pedido'
				},
				ar: {
					orders: 'الطلبات',
					recentMatching: 'الطلبات المطابقة الأخيرة',
					recentMatchingSubtle: 'تظهر الطلبات المطابقة الأخيرة أدناه.',
					needsAttention: 'تحتاج إلى مراجعة',
					needsAttentionSubtle: 'معلقة أو قيد الانتظار أو فاشلة',
					combinedTotal: 'الإجمالي',
					combinedTotalSubtle: 'ضمن النتائج الظاهرة',
					averageAge: 'متوسط العمر',
					visibleResults: 'النتائج الظاهرة',
					hold: 'تعليق',
					viewDetails: 'عرض التفاصيل',
					addNote: 'إضافة ملاحظة',
					updateStatus: 'تحديث الحالة',
					statusNote: 'الحالة + ملاحظة',
					order: 'الطلب',
					customer: 'العميل',
					case: 'الحالة',
					total: 'الإجمالي',
					email: 'البريد الإلكتروني',
					date: 'التاريخ',
					age: 'العمر',
					nextAction: 'الإجراء التالي',
					actions: 'الإجراءات',
					open: 'فتح',
					showFirstOrder: 'عرض الطلب الأول',
					latestFiveOrders: 'آخر 5 طلبات',
					whatNext: 'الخطوة التالية',
					decisionRoute: 'مسار القرار',
					confidence: 'الثقة',
					orderReview: 'مراجعة الطلب'
				}
			};
			const labels = labelsByLocale[locale] || {};

			return labels[key] || fallback;
		}

		function orderCountLabel(count, locale) {
			count = parseInt(count || 0, 10) || 0;
			locale = normalizeLocale(locale);

			if (isVietnamese(locale)) {
				return count + ' đơn';
			}

			const nouns = {
				es: ['pedido', 'pedidos'],
				fr: ['commande', 'commandes'],
				de: ['Bestellung', 'Bestellungen'],
				ja: ['件の注文', '件の注文'],
				th: ['คำสั่งซื้อ', 'คำสั่งซื้อ'],
				id: ['pesanan', 'pesanan'],
				pt: ['pedido', 'pedidos'],
				ar: ['طلب', 'طلبات']
			};

			if (locale === 'ja') {
				return count + nouns.ja[1];
			}

			if (nouns[locale]) {
				return count + ' ' + (count === 1 ? nouns[locale][0] : nouns[locale][1]);
			}

			return count + ' order' + (count === 1 ? '' : 's');
		}

		function orderStatusLabel(status, locale) {
			status = String(status || '').trim();
			locale = normalizeLocale(locale);

			const labelsByLocale = {
				vi: {
					pending: 'đang chờ xử lý',
					processing: 'đang xử lý',
					completed: 'hoàn tất',
					'on-hold': 'tạm giữ',
					cancelled: 'đã hủy',
					canceled: 'đã hủy',
					refunded: 'đã hoàn tiền',
					failed: 'thất bại',
					'request-cancel': 'yêu cầu hủy'
				},
				es: {
					pending: 'pendiente',
					processing: 'en proceso',
					completed: 'completado',
					'on-hold': 'en espera',
					cancelled: 'cancelado',
					canceled: 'cancelado',
					refunded: 'reembolsado',
					failed: 'fallido',
					'request-cancel': 'solicitud de cancelación'
				},
				fr: {
					pending: 'en attente',
					processing: 'en traitement',
					completed: 'terminée',
					'on-hold': 'en pause',
					cancelled: 'annulée',
					canceled: 'annulée',
					refunded: 'remboursée',
					failed: 'échouée',
					'request-cancel': 'demande d’annulation'
				},
				de: {
					pending: 'ausstehend',
					processing: 'in Bearbeitung',
					completed: 'abgeschlossen',
					'on-hold': 'zurückgestellt',
					cancelled: 'storniert',
					canceled: 'storniert',
					refunded: 'erstattet',
					failed: 'fehlgeschlagen',
					'request-cancel': 'Stornierungsanfrage'
				},
				ja: {
					pending: '保留中',
					processing: '処理中',
					completed: '完了済み',
					'on-hold': '保留',
					cancelled: 'キャンセル済み',
					canceled: 'キャンセル済み',
					refunded: '返金済み',
					failed: '失敗',
					'request-cancel': 'キャンセル依頼'
				},
				th: {
					pending: 'รอดำเนินการ',
					processing: 'กำลังประมวลผล',
					completed: 'เสร็จสมบูรณ์',
					'on-hold': 'พักไว้',
					cancelled: 'ยกเลิกแล้ว',
					canceled: 'ยกเลิกแล้ว',
					refunded: 'คืนเงินแล้ว',
					failed: 'ล้มเหลว',
					'request-cancel': 'คำขอยกเลิก'
				},
				id: {
					pending: 'tertunda',
					processing: 'sedang diproses',
					completed: 'selesai',
					'on-hold': 'ditahan',
					cancelled: 'dibatalkan',
					canceled: 'dibatalkan',
					refunded: 'dikembalikan',
					failed: 'gagal',
					'request-cancel': 'permintaan pembatalan'
				},
				pt: {
					pending: 'pendente',
					processing: 'em processamento',
					completed: 'concluído',
					'on-hold': 'em espera',
					cancelled: 'cancelado',
					canceled: 'cancelado',
					refunded: 'reembolsado',
					failed: 'falhado',
					'request-cancel': 'pedido de cancelamento'
				},
				ar: {
					pending: 'معلق',
					processing: 'قيد المعالجة',
					completed: 'مكتمل',
					'on-hold': 'قيد الانتظار',
					cancelled: 'ملغى',
					canceled: 'ملغى',
					refunded: 'مسترد',
					failed: 'فاشل',
					'request-cancel': 'طلب إلغاء'
				}
			};
			const labels = labelsByLocale[locale] || {};

			return labels[status] || status.replace(/-/g, ' ');
		}

		function operationalUiText(locale, key, fallback) {
			locale = normalizeLocale(locale);
			const labelsByLocale = {
				vi: {
					operationsSummary: 'Tổng quan vận hành',
					storeOperations: 'Vận hành cửa hàng',
					newOrders: 'Đơn mới',
					openBacklog: 'Tồn đọng đang mở',
					ordersInWindow: 'Đơn trong khoảng thời gian',
					status: 'Trạng thái',
					count: 'Số lượng',
					currentBacklog: 'Tồn đọng hiện tại',
					queue: 'Hàng đợi',
					agedQueues: 'Hàng đợi quá hạn',
					recentOpenOrders: 'Đơn đang mở gần đây',
					details: 'Chi tiết',
					suggestedNextChecks: 'Kiểm tra tiếp theo',
					pendingOrders: 'Đơn đang chờ',
					processingOrders: 'Đơn đang xử lý',
					onHoldOrders: 'Đơn tạm giữ',
					window: 'Khoảng thời gian',
					overview: 'Tổng quan'
				},
				es: {
					operationsSummary: 'Resumen operativo',
					storeOperations: 'Operaciones de la tienda',
					newOrders: 'Pedidos nuevos',
					openBacklog: 'Backlog abierto',
					ordersInWindow: 'Pedidos en el periodo',
					status: 'Estado',
					count: 'Cantidad',
					currentBacklog: 'Backlog actual',
					queue: 'Cola',
					agedQueues: 'Colas con antigüedad',
					recentOpenOrders: 'Pedidos abiertos recientes',
					details: 'Detalles',
					suggestedNextChecks: 'Siguientes revisiones sugeridas',
					pendingOrders: 'Pedidos pendientes',
					processingOrders: 'Pedidos en proceso',
					onHoldOrders: 'Pedidos en espera',
					window: 'Periodo',
					overview: 'Resumen'
				},
				fr: {
					operationsSummary: 'Résumé opérationnel',
					storeOperations: 'Opérations de la boutique',
					newOrders: 'Nouvelles commandes',
					openBacklog: 'Backlog ouvert',
					ordersInWindow: 'Commandes sur la période',
					status: 'Statut',
					count: 'Nombre',
					currentBacklog: 'Backlog actuel',
					queue: 'File',
					agedQueues: 'Files anciennes',
					recentOpenOrders: 'Commandes ouvertes récentes',
					details: 'Détails',
					suggestedNextChecks: 'Prochaines vérifications',
					pendingOrders: 'Commandes en attente',
					processingOrders: 'Commandes en traitement',
					onHoldOrders: 'Commandes en pause',
					window: 'Période',
					overview: 'Vue d’ensemble'
				},
				de: {
					operationsSummary: 'Betriebsübersicht',
					storeOperations: 'Shop-Betrieb',
					newOrders: 'Neue Bestellungen',
					openBacklog: 'Offener Rückstand',
					ordersInWindow: 'Bestellungen im Zeitraum',
					status: 'Status',
					count: 'Anzahl',
					currentBacklog: 'Aktueller Rückstand',
					queue: 'Warteschlange',
					agedQueues: 'Überfällige Warteschlangen',
					recentOpenOrders: 'Aktuelle offene Bestellungen',
					details: 'Details',
					suggestedNextChecks: 'Empfohlene nächste Prüfungen',
					pendingOrders: 'Ausstehende Bestellungen',
					processingOrders: 'Bestellungen in Bearbeitung',
					onHoldOrders: 'Zurückgestellte Bestellungen',
					window: 'Zeitraum',
					overview: 'Übersicht'
				},
				ja: {
					operationsSummary: '運用サマリー',
					storeOperations: 'ストア運用',
					newOrders: '新規注文',
					openBacklog: '未処理バックログ',
					ordersInWindow: '期間内の注文',
					status: 'ステータス',
					count: '件数',
					currentBacklog: '現在のバックログ',
					queue: 'キュー',
					agedQueues: '滞留キュー',
					recentOpenOrders: '最近の未処理注文',
					details: '詳細',
					suggestedNextChecks: '次の確認候補',
					pendingOrders: '保留中の注文',
					processingOrders: '処理中の注文',
					onHoldOrders: '保留の注文',
					window: '期間',
					overview: '概要'
				},
				th: {
					operationsSummary: 'สรุปการดำเนินงาน',
					storeOperations: 'การดำเนินงานร้านค้า',
					newOrders: 'คำสั่งซื้อใหม่',
					openBacklog: 'งานค้างที่เปิดอยู่',
					ordersInWindow: 'คำสั่งซื้อในช่วงเวลา',
					status: 'สถานะ',
					count: 'จำนวน',
					currentBacklog: 'งานค้างปัจจุบัน',
					queue: 'คิว',
					agedQueues: 'คิวที่ค้างนาน',
					recentOpenOrders: 'คำสั่งซื้อเปิดล่าสุด',
					details: 'รายละเอียด',
					suggestedNextChecks: 'การตรวจสอบถัดไป',
					pendingOrders: 'คำสั่งซื้อที่รอดำเนินการ',
					processingOrders: 'คำสั่งซื้อที่กำลังประมวลผล',
					onHoldOrders: 'คำสั่งซื้อที่พักไว้',
					window: 'ช่วงเวลา',
					overview: 'ภาพรวม'
				},
				id: {
					operationsSummary: 'Ringkasan operasional',
					storeOperations: 'Operasional toko',
					newOrders: 'Pesanan baru',
					openBacklog: 'Backlog terbuka',
					ordersInWindow: 'Pesanan dalam periode',
					status: 'Status',
					count: 'Jumlah',
					currentBacklog: 'Backlog saat ini',
					queue: 'Antrean',
					agedQueues: 'Antrean lama',
					recentOpenOrders: 'Pesanan terbuka terbaru',
					details: 'Detail',
					suggestedNextChecks: 'Pemeriksaan berikutnya',
					pendingOrders: 'Pesanan tertunda',
					processingOrders: 'Pesanan diproses',
					onHoldOrders: 'Pesanan ditahan',
					window: 'Periode',
					overview: 'Ringkasan'
				},
				pt: {
					operationsSummary: 'Resumo operacional',
					storeOperations: 'Operações da loja',
					newOrders: 'Novos pedidos',
					openBacklog: 'Backlog aberto',
					ordersInWindow: 'Pedidos no período',
					status: 'Estado',
					count: 'Contagem',
					currentBacklog: 'Backlog atual',
					queue: 'Fila',
					agedQueues: 'Filas antigas',
					recentOpenOrders: 'Pedidos abertos recentes',
					details: 'Detalhes',
					suggestedNextChecks: 'Próximas verificações',
					pendingOrders: 'Pedidos pendentes',
					processingOrders: 'Pedidos em processamento',
					onHoldOrders: 'Pedidos em espera',
					window: 'Período',
					overview: 'Visão geral'
				},
				ar: {
					operationsSummary: 'ملخص العمليات',
					storeOperations: 'عمليات المتجر',
					newOrders: 'طلبات جديدة',
					openBacklog: 'تراكم مفتوح',
					ordersInWindow: 'الطلبات في الفترة',
					status: 'الحالة',
					count: 'العدد',
					currentBacklog: 'التراكم الحالي',
					queue: 'القائمة',
					agedQueues: 'قوائم قديمة',
					recentOpenOrders: 'طلبات مفتوحة حديثة',
					details: 'التفاصيل',
					suggestedNextChecks: 'الفحوصات التالية المقترحة',
					pendingOrders: 'طلبات معلقة',
					processingOrders: 'طلبات قيد المعالجة',
					onHoldOrders: 'طلبات قيد الانتظار',
					window: 'الفترة',
					overview: 'نظرة عامة'
				}
			};
			const labels = labelsByLocale[locale] || {};
			return labels[key] || fallback;
		}

		function businessUiText(locale, key, fallback) {
			locale = normalizeLocale(locale);
			const labelsByLocale = {
				vi: {
					businessSummary: 'Tổng quan kinh doanh',
					readOnly: 'Tóm tắt chỉ đọc. Không thay đổi dữ liệu cửa hàng.',
					businessOverview: 'Bức tranh kinh doanh',
					grossSales: 'Doanh thu gộp',
					customers: 'Khách hàng',
					attention: 'Cần chú ý',
					mainSignal: 'Tín hiệu chính',
					seeDetailView: 'Xem chi tiết',
					seeDetailSubtitle: 'Mở chi tiết thương mại, cơ cấu đơn và nhóm nổi bật',
					orderMix: 'Cơ cấu đơn',
					operatingPressure: 'Áp lực vận hành',
					attentionItems: 'Mục cần chú ý',
					completedSales: 'Doanh thu hoàn tất',
					topCustomers: 'Khách hàng hàng đầu',
					topProducts: 'Sản phẩm hàng đầu',
					product: 'Sản phẩm',
					qty: 'SL',
					totalSpent: 'Tổng chi tiêu',
					recentOrders: 'Đơn gần đây',
					aov: 'AOV',
					productLines: 'Dòng sản phẩm',
					backlog: 'Tồn đọng',
					otherPressures: 'Áp lực khác cần theo dõi',
					overview: 'Tổng quan'
				},
				es: {
					businessSummary: 'Resumen de negocio',
					readOnly: 'Resumen ejecutivo de solo lectura. No se cambia ningún dato de la tienda.',
					businessOverview: 'Visión general del negocio',
					grossSales: 'Ventas brutas',
					customers: 'Clientes',
					attention: 'Atención',
					mainSignal: 'Señal principal',
					seeDetailView: 'Ver detalle',
					seeDetailSubtitle: 'Abrir detalle comercial, mezcla de pedidos y mejores resultados',
					orderMix: 'Mezcla de pedidos',
					operatingPressure: 'Presión operativa',
					attentionItems: 'Elementos que requieren atención',
					completedSales: 'Ventas completadas',
					topCustomers: 'Clientes principales',
					topProducts: 'Productos principales',
					product: 'Producto',
					qty: 'Cant.',
					totalSpent: 'Gasto total',
					recentOrders: 'Pedidos recientes',
					aov: 'AOV',
					productLines: 'Líneas de producto',
					backlog: 'Backlog',
					otherPressures: 'Otras presiones a vigilar',
					overview: 'Resumen'
				},
				fr: {
					businessSummary: 'Résumé commercial',
					readOnly: 'Résumé exécutif en lecture seule. Aucune donnée de la boutique n’est modifiée.',
					businessOverview: 'Vue d’ensemble commerciale',
					grossSales: 'Ventes brutes',
					customers: 'Clients',
					attention: 'Attention',
					mainSignal: 'Signal principal',
					seeDetailView: 'Voir le détail',
					seeDetailSubtitle: 'Ouvrir le détail commercial, le mix de commandes et les meilleurs résultats',
					orderMix: 'Mix de commandes',
					operatingPressure: 'Pression opérationnelle',
					attentionItems: 'Éléments à traiter',
					completedSales: 'Ventes terminées',
					topCustomers: 'Meilleurs clients',
					topProducts: 'Meilleurs produits',
					product: 'Produit',
					qty: 'Qté',
					totalSpent: 'Dépense totale',
					recentOrders: 'Commandes récentes',
					aov: 'Panier moyen',
					productLines: 'Lignes produit',
					backlog: 'Backlog',
					otherPressures: 'Autres pressions à surveiller',
					overview: 'Vue d’ensemble'
				},
				id: {
					businessSummary: 'Ringkasan bisnis',
					readOnly: 'Ringkasan eksekutif baca-saja. Data toko tidak diubah.',
					businessOverview: 'Gambaran bisnis',
					grossSales: 'Penjualan kotor',
					customers: 'Pelanggan',
					attention: 'Perhatian',
					mainSignal: 'Sinyal utama',
					seeDetailView: 'Lihat detail',
					seeDetailSubtitle: 'Buka detail komersial, komposisi pesanan, dan performa teratas',
					orderMix: 'Komposisi pesanan',
					operatingPressure: 'Tekanan operasional',
					attentionItems: 'Item yang perlu perhatian',
					completedSales: 'Penjualan selesai',
					topCustomers: 'Pelanggan teratas',
					topProducts: 'Produk teratas',
					product: 'Produk',
					qty: 'Jml',
					totalSpent: 'Total belanja',
					recentOrders: 'Pesanan terbaru',
					aov: 'AOV',
					productLines: 'Lini produk',
					backlog: 'Backlog',
					otherPressures: 'Tekanan lain untuk dipantau',
					overview: 'Ringkasan'
				},
				de: {
					businessSummary: 'Geschäftsübersicht',
					readOnly: 'Schreibgeschützte Management-Zusammenfassung. Shop-Daten werden nicht geändert.',
					businessOverview: 'Geschäftsüberblick',
					grossSales: 'Bruttoumsatz',
					customers: 'Kunden',
					attention: 'Aufmerksamkeit',
					mainSignal: 'Hauptsignal',
					seeDetailView: 'Details anzeigen',
					seeDetailSubtitle: 'Kommerzielle Details, Bestellmix und Top-Leistung öffnen',
					orderMix: 'Bestellmix',
					operatingPressure: 'Operativer Druck',
					attentionItems: 'Elemente mit Handlungsbedarf',
					completedSales: 'Abgeschlossene Verkäufe',
					topCustomers: 'Top-Kunden',
					topProducts: 'Top-Produkte',
					product: 'Produkt',
					qty: 'Menge',
					totalSpent: 'Gesamtausgaben',
					recentOrders: 'Aktuelle Bestellungen',
					aov: 'AOV',
					productLines: 'Produktlinien',
					backlog: 'Rückstand',
					otherPressures: 'Weitere Druckpunkte beobachten',
					overview: 'Übersicht'
				},
				ja: {
					businessSummary: 'ビジネスサマリー',
					readOnly: '読み取り専用の経営サマリーです。ストアデータは変更されません。',
					businessOverview: 'ビジネス概要',
					grossSales: '総売上',
					customers: '顧客',
					attention: '要対応',
					mainSignal: '主なシグナル',
					seeDetailView: '詳細を見る',
					seeDetailSubtitle: '売上詳細、注文構成、上位実績を開く',
					orderMix: '注文構成',
					operatingPressure: '運用負荷',
					attentionItems: '要対応項目',
					completedSales: '完了売上',
					topCustomers: '上位顧客',
					topProducts: '上位商品',
					product: '商品',
					qty: '数量',
					totalSpent: '合計購入額',
					recentOrders: '最近の注文',
					aov: '平均注文額',
					productLines: '商品ライン',
					backlog: 'バックログ',
					otherPressures: '注視すべき他の負荷',
					overview: '概要'
				},
				th: {
					businessSummary: 'สรุปธุรกิจ',
					readOnly: 'สรุปสำหรับผู้บริหารแบบอ่านอย่างเดียว ไม่มีการเปลี่ยนแปลงข้อมูลร้านค้า',
					businessOverview: 'ภาพรวมธุรกิจ',
					grossSales: 'ยอดขายรวม',
					customers: 'ลูกค้า',
					attention: 'ต้องตรวจสอบ',
					mainSignal: 'สัญญาณหลัก',
					seeDetailView: 'ดูรายละเอียด',
					seeDetailSubtitle: 'เปิดรายละเอียดเชิงพาณิชย์ ส่วนผสมคำสั่งซื้อ และรายการเด่น',
					orderMix: 'ส่วนผสมคำสั่งซื้อ',
					operatingPressure: 'แรงกดดันด้านปฏิบัติการ',
					attentionItems: 'รายการที่ต้องตรวจสอบ',
					completedSales: 'ยอดขายที่เสร็จสมบูรณ์',
					topCustomers: 'ลูกค้าสูงสุด',
					topProducts: 'สินค้าสูงสุด',
					product: 'สินค้า',
					qty: 'จำนวน',
					totalSpent: 'ยอดใช้จ่ายรวม',
					recentOrders: 'คำสั่งซื้อล่าสุด',
					aov: 'AOV',
					productLines: 'กลุ่มสินค้า',
					backlog: 'งานค้าง',
					otherPressures: 'แรงกดดันอื่นที่ควรติดตาม',
					overview: 'ภาพรวม'
				},
				pt: {
					businessSummary: 'Resumo do negócio',
					readOnly: 'Resumo executivo somente leitura. Nenhum dado da loja é alterado.',
					businessOverview: 'Visão geral do negócio',
					grossSales: 'Vendas brutas',
					customers: 'Clientes',
					attention: 'Atenção',
					mainSignal: 'Sinal principal',
					seeDetailView: 'Ver detalhes',
					seeDetailSubtitle: 'Abrir detalhe comercial, mix de pedidos e melhores desempenhos',
					orderMix: 'Mix de pedidos',
					operatingPressure: 'Pressão operacional',
					attentionItems: 'Itens que precisam de atenção',
					completedSales: 'Vendas concluídas',
					topCustomers: 'Principais clientes',
					topProducts: 'Principais produtos',
					product: 'Produto',
					qty: 'Qtd.',
					totalSpent: 'Total gasto',
					recentOrders: 'Pedidos recentes',
					aov: 'AOV',
					productLines: 'Linhas de produto',
					backlog: 'Backlog',
					otherPressures: 'Outras pressões a acompanhar',
					overview: 'Visão geral'
				},
				ar: {
					businessSummary: 'ملخص الأعمال',
					readOnly: 'ملخص تنفيذي للقراءة فقط. لا يتم تغيير بيانات المتجر.',
					businessOverview: 'نظرة عامة على الأعمال',
					grossSales: 'إجمالي المبيعات',
					customers: 'العملاء',
					attention: 'تحتاج إلى مراجعة',
					mainSignal: 'الإشارة الرئيسية',
					seeDetailView: 'عرض التفاصيل',
					seeDetailSubtitle: 'فتح التفاصيل التجارية ومزيج الطلبات وأفضل الأداء',
					orderMix: 'مزيج الطلبات',
					operatingPressure: 'ضغط العمليات',
					attentionItems: 'عناصر تحتاج إلى مراجعة',
					completedSales: 'المبيعات المكتملة',
					topCustomers: 'أفضل العملاء',
					topProducts: 'أفضل المنتجات',
					product: 'المنتج',
					qty: 'الكمية',
					totalSpent: 'إجمالي الإنفاق',
					recentOrders: 'الطلبات الحديثة',
					aov: 'متوسط قيمة الطلب',
					productLines: 'خطوط المنتجات',
					backlog: 'التراكم',
					otherPressures: 'ضغوط أخرى للمتابعة',
					overview: 'نظرة عامة'
				}
			};
			const labels = labelsByLocale[locale] || {};
			return labels[key] || fallback;
		}

		function localizedWindowLabel(windowInfo, locale) {
			const key = String((windowInfo && windowInfo.key) || '').toLowerCase();
			const fallback = String((windowInfo && windowInfo.label) || '').trim();
			const labels = {
				es: { today: 'hoy', this_week: 'esta semana', this_month: 'este mes', last_7_days: 'los últimos 7 días', last_30_days: 'los últimos 30 días' },
				fr: { today: 'aujourd’hui', this_week: 'cette semaine', this_month: 'ce mois-ci', last_7_days: 'les 7 derniers jours', last_30_days: 'les 30 derniers jours' },
				de: { today: 'heute', this_week: 'diese Woche', this_month: 'diesen Monat', last_7_days: 'die letzten 7 Tage', last_30_days: 'die letzten 30 Tage' },
				ja: { today: '今日', this_week: '今週', this_month: '今月', last_7_days: '過去7日間', last_30_days: '過去30日間' },
				th: { today: 'วันนี้', this_week: 'สัปดาห์นี้', this_month: 'เดือนนี้', last_7_days: '7 วันที่ผ่านมา', last_30_days: '30 วันที่ผ่านมา' },
				id: { today: 'hari ini', this_week: 'minggu ini', this_month: 'bulan ini', last_7_days: '7 hari terakhir', last_30_days: '30 hari terakhir' },
				pt: { today: 'hoje', this_week: 'esta semana', this_month: 'este mês', last_7_days: 'últimos 7 dias', last_30_days: 'últimos 30 dias' },
				ar: { today: 'اليوم', this_week: 'هذا الأسبوع', this_month: 'هذا الشهر', last_7_days: 'آخر 7 أيام', last_30_days: 'آخر 30 يوما' },
				vi: { today: 'hôm nay', this_week: 'tuần này', this_month: 'tháng này', last_7_days: '7 ngày qua', last_30_days: '30 ngày qua' }
			};
			locale = normalizeLocale(locale);
			return labels[locale] && labels[locale][key] ? labels[locale][key] : fallback;
		}

		function localizedOperationsSummary(result, locale) {
			const windowLabel = localizedWindowLabel(result.window || {}, locale);
			const orders = result.window_order_count || 0;
			const backlog = result.backlog_total || 0;
			const attention = result.attention_total || 0;
			locale = normalizeLocale(locale);

			if (locale === 'es') {
				return 'Resumen operativo de ' + windowLabel + ': ' + orders + ' pedidos nuevos, ' + backlog + ' en backlog, ' + attention + ' elementos requieren atención.';
			}
			if (locale === 'id') {
				return 'Ringkasan operasional ' + windowLabel + ': ' + orders + ' pesanan baru, ' + backlog + ' backlog terbuka, ' + attention + ' item perlu perhatian.';
			}
			if (locale === 'fr') {
				return 'Résumé opérationnel pour ' + windowLabel + ' : ' + orders + ' nouvelles commandes, ' + backlog + ' en backlog, ' + attention + ' éléments à traiter.';
			}
			if (locale === 'de') {
				return 'Betriebsübersicht für ' + windowLabel + ': ' + orders + ' neue Bestellungen, ' + backlog + ' im Rückstand, ' + attention + ' Elemente benötigen Aufmerksamkeit.';
			}
			if (locale === 'ja') {
				return windowLabel + 'の運用サマリー: 新規注文 ' + orders + ' 件、バックログ ' + backlog + ' 件、要対応 ' + attention + ' 件。';
			}
			if (locale === 'th') {
				return 'สรุปการดำเนินงาน' + windowLabel + ': คำสั่งซื้อใหม่ ' + orders + ' รายการ, งานค้าง ' + backlog + ' รายการ, ต้องตรวจสอบ ' + attention + ' รายการ';
			}
			if (locale === 'pt') {
				return 'Resumo operacional de ' + windowLabel + ': ' + orders + ' novos pedidos, ' + backlog + ' no backlog, ' + attention + ' itens precisam de atenção.';
			}
			if (locale === 'ar') {
				return 'ملخص العمليات لـ ' + windowLabel + ': ' + orders + ' طلبات جديدة، ' + backlog + ' في التراكم، ' + attention + ' عناصر تحتاج إلى مراجعة.';
			}

			return result.summary || 'Operations summary';
		}

		function localizedBusinessSummary(result, locale) {
			const windowLabel = localizedWindowLabel(result.window || {}, locale);
			const metrics = result.business_metrics || {};
			const currency = result.meta && result.meta.currency ? (' ' + result.meta.currency) : '';
			const grossSales = (metrics.gross_sales || 0) + currency;
			const orders = metrics.order_count || 0;
			const customers = metrics.unique_customers || 0;
			locale = normalizeLocale(locale);

			if (locale === 'es') {
				return 'Resumen de negocio de ' + windowLabel + ': ' + orders + ' pedidos, ' + grossSales + ' en ventas brutas, ' + customers + ' clientes activos.';
			}
			if (locale === 'id') {
				return 'Ringkasan bisnis ' + windowLabel + ': ' + orders + ' pesanan, ' + grossSales + ' penjualan kotor, ' + customers + ' pelanggan aktif.';
			}
			if (locale === 'fr') {
				return 'Résumé commercial pour ' + windowLabel + ' : ' + orders + ' commandes, ' + grossSales + ' de ventes brutes, ' + customers + ' clients actifs.';
			}
			if (locale === 'de') {
				return 'Geschäftsübersicht für ' + windowLabel + ': ' + orders + ' Bestellungen, ' + grossSales + ' Bruttoumsatz, ' + customers + ' aktive Kunden.';
			}
			if (locale === 'ja') {
				return windowLabel + 'のビジネスサマリー: 注文 ' + orders + ' 件、総売上 ' + grossSales + '、アクティブ顧客 ' + customers + ' 名。';
			}
			if (locale === 'th') {
				return 'สรุปธุรกิจ' + windowLabel + ': ' + orders + ' คำสั่งซื้อ, ยอดขายรวม ' + grossSales + ', ลูกค้าที่ใช้งาน ' + customers + ' ราย';
			}
			if (locale === 'pt') {
				return 'Resumo do negócio de ' + windowLabel + ': ' + orders + ' pedidos, ' + grossSales + ' em vendas brutas, ' + customers + ' clientes ativos.';
			}
			if (locale === 'ar') {
				return 'ملخص الأعمال لـ ' + windowLabel + ': ' + orders + ' طلبات، ' + grossSales + ' إجمالي المبيعات، ' + customers + ' عملاء نشطون.';
			}

			return result.summary || 'Business summary';
		}

		function queueLabel(key, locale) {
			const normalized = String(key || '').toLowerCase();
			const map = {
				pending_over_24h: {
					es: 'Pendientes de más de 24 h',
					id: 'Tertunda lebih dari 24 jam',
					fr: 'En attente depuis plus de 24 h',
					de: 'Seit mehr als 24 Std. ausstehend',
					ja: '24時間超の保留',
					th: 'รอดำเนินการเกิน 24 ชม.',
					pt: 'Pendentes há mais de 24 h',
					ar: 'معلقة لأكثر من 24 ساعة'
				},
				processing_over_72h: {
					es: 'En proceso de más de 72 h',
					id: 'Diproses lebih dari 72 jam',
					fr: 'En traitement depuis plus de 72 h',
					de: 'Seit mehr als 72 Std. in Bearbeitung',
					ja: '72時間超の処理中',
					th: 'กำลังประมวลผลเกิน 72 ชม.',
					pt: 'Em processamento há mais de 72 h',
					ar: 'قيد المعالجة لأكثر من 72 ساعة'
				},
				on_hold_over_48h: {
					es: 'En espera de más de 48 h',
					id: 'Ditahan lebih dari 48 jam',
					fr: 'En pause depuis plus de 48 h',
					de: 'Seit mehr als 48 Std. zurückgestellt',
					ja: '48時間超の保留',
					th: 'พักไว้เกิน 48 ชม.',
					pt: 'Em espera há mais de 48 h',
					ar: 'قيد الانتظار لأكثر من 48 ساعة'
				}
			};
			locale = normalizeLocale(locale);
			if (map[normalized] && map[normalized][locale]) {
				return map[normalized][locale];
			}
			if (['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed', 'request-cancel'].indexOf(normalized) !== -1) {
				return orderStatusLabel(normalized, locale);
			}
			return normalized.replace(/_/g, ' ').replace(/\b\w/g, function(char) {
				return char.toUpperCase();
			});
		}

		function localizedFocusLabel(value, locale, textResolver) {
			const normalized = String(value || 'overview').toLowerCase();

			if (normalized === 'overview') {
				return textResolver(locale, 'overview', 'overview');
			}

			return value || textResolver(locale, 'overview', 'overview');
		}

	function paymentMethodLabel(method, locale) {
		method = String(method || '').trim();

		if (!isVietnamese(locale)) {
			return method;
		}

		const normalized = method.toLowerCase();
		const labels = {
			cod: 'Thanh toán khi nhận hàng',
			'cash on delivery': 'Thanh toán khi nhận hàng'
		};

		return labels[normalized] || method;
	}

	function formatAgeHoursForLocale(value, locale) {
		if (!isVietnamese(locale)) {
			return formatAgeHours(value);
		}

		const hours = parseInt(value || 0, 10) || 0;

		if (hours <= 0) {
			return '<1 giờ';
		}

		if (hours < 24) {
			return hours + ' giờ';
		}

		const days = Math.floor(hours / 24);
		const remainingHours = hours % 24;

		if (days < 7) {
			return remainingHours ? (days + ' ngày ' + remainingHours + ' giờ') : (days + ' ngày');
		}

		const weeks = Math.floor(days / 7);
		const remainingDays = days % 7;

		if (weeks < 5) {
			return remainingDays ? (weeks + ' tuần ' + remainingDays + ' ngày') : (weeks + ' tuần');
		}

		const months = Math.floor(days / 30);
		const remainingMonthDays = days % 30;

		if (months > 0) {
			return remainingMonthDays ? (months + ' tháng ' + remainingMonthDays + ' ngày') : (months + ' tháng');
		}

		return days + ' ngày';
	}

	function shouldShowDecisionRoute(h) {
		return !!(h && typeof h.shouldShowDebugJson === 'function' && h.shouldShowDebugJson());
	}

	function formatWindowRange(windowInfo, h) {
		windowInfo = windowInfo || {};

		const start = formatDateTime(windowInfo.start, windowInfo.start_ts, h);
		const end = formatDateTime(windowInfo.end, windowInfo.end_ts, h);

		if (start && end) {
			return start + ' → ' + end;
		}

		return start || end || '';
	}

	function inferCalendarWindowFromCommand(normalized) {
		if (/\bthis\s+week\b/i.test(normalized)) {
			return 'this_week';
		}

		if (/\b(last|past)\s+7\s+days\b/i.test(normalized) || /\bpast\s+week\b/i.test(normalized)) {
			return 'last_7_days';
		}

		if (/\b(last|past)\s+30\s+days\b/i.test(normalized) || /\bpast\s+month\b/i.test(normalized)) {
			return 'last_30_days';
		}

		if (/\bthis\s+month\b/i.test(normalized)) {
			return 'this_month';
		}

		if (/\blast\s+month\b/i.test(normalized)) {
			return 'last_month';
		}

		if (/\blast\s+week\b/i.test(normalized)) {
			return 'last_week';
		}

		if (/\bweekly\b|\bweek\b/i.test(normalized)) {
			return 'this_week';
		}

		return 'today';
	}

	function getDirectActionForCommand(command) {
		const text = String(command || '').trim();
		const normalized = text.toLowerCase();

		if (!text) {
			return null;
		}

		let match = text.match(/\border\s*#?\s*(\d+)\b/i) || text.match(/\border\s+(\d+)\s+details?\b/i);

		if (match && /\b(show|view|open|details?)\b/i.test(text)) {
			const orderId = parseInt(match[1], 10);

			if (orderId > 0) {
				return {
					action: 'orders.get_details',
					params: {
						order_id: orderId,
						order_ids: [orderId]
					}
				};
			}
		}

		match = text.match(/preview\s+shipping\s+delay\s+email\s+for\s+order\s*#?\s*(\d+)/i);

		if (match) {
			const orderId = parseInt(match[1], 10);

			if (orderId > 0) {
				return {
					action: 'orders.email_customer_preview',
					params: {
						order_id: orderId,
						template: 'shipping_delay'
					}
				};
			}
		}

		match = text.match(/load\s+email\s+drafts\s+for\s+order\s*#?\s*(\d+)/i);

		if (match) {
			const orderId = parseInt(match[1], 10);

			if (orderId > 0) {
				return {
					action: 'orders.get_customer_email_drafts',
					params: {
						order_id: orderId
					}
				};
			}
		}

		match = text.match(/write\s+email\s+for\s+order\s*#?\s*(\d+)(?:\s+about:\s*(.+))?/i);

		if (match) {
			const orderId = parseInt(match[1], 10);
			const instruction = String(match[2] || 'Write a concise customer follow-up.').trim();

			if (orderId > 0) {
				return {
					action: 'orders.compose_customer_email',
					params: {
						order_id: orderId,
						instruction: instruction
					}
				};
			}
		}

		if (/show\s+(pending|processing|on-hold|completed|cancelled|refunded|failed)\s+orders/i.test(normalized)) {
			const status = normalized.match(/show\s+(pending|processing|on-hold|completed|cancelled|refunded|failed)\s+orders/i)[1];

			return {
				action: 'orders.search',
				params: {
					status: [status],
					limit: 10
				}
			};
		}

		const directMap = {
			'show latest 5 orders': { action: 'orders.search', params: { limit: 5 } },
			'show operations attention queue': { action: 'operations.get_attention_queue', params: {} },
			'show fulfillment queue': { action: 'operations.get_fulfillment_queue', params: {} },
			'show customer service queue': { action: 'customers.get_service_queue', params: {} },
			'show inventory queue': { action: 'products.get_inventory_queue', params: {} },
			'show morning briefing': { action: 'store.get_morning_briefing', params: {} },
			'continue with the first recommendation': null,
			'continue previous workflow': null,
			'continue the plan': null,
			'do the next recommendation': null
		};

		if (Object.prototype.hasOwnProperty.call(directMap, normalized)) {
			return directMap[normalized];
		}

		if (/show operations summary/i.test(normalized)) {
			return {
				action: 'operations.get_summary',
				params: {
					window: inferCalendarWindowFromCommand(normalized)
				}
			};
		}

		if (/show business summary/i.test(normalized)) {
			return {
				action: 'store.get_business_summary',
				params: {
					window: inferCalendarWindowFromCommand(normalized)
				}
			};
		}

		return null;
	}

	function renderDirectActionButton(label, command, h, className) {
		const direct = getDirectActionForCommand(command);
		const classes = className || 'button button-small';

		if (!direct || !direct.action) {
			return '';
		}

		let html = '<button type="button" class="' + h.escapeHtml(classes + ' yoro-suggestion-btn') + '"';

		html += ' data-action="' + h.escapeHtml(direct.action) + '"';
		html += ' data-params="' + h.escapeHtml(JSON.stringify(direct.params || {})) + '"';
		html += ' data-confirm="0" data-preview="0"';

		html += '>' + h.escapeHtml(label || command || 'Open') + '</button>';

		return html;
	}

	function executeOrderUiAction(h, action, params, preview) {
		if (!action || !h.executeActionDirect) {
			return;
		}

		const loading = h.addLoadingMessage ? h.addLoadingMessage() : null;

		h.executeActionDirect(action, params || {}, false, !!preview, 'order_ui')
			.then(function(data) {
				if (loading) {
					loading.remove();
				}

				h.addAssistantMessage(data);
				h.updateConversationState(data, 'direct:' + action);
			})
			.catch(function(error) {
				if (loading) {
					loading.remove();
				}

				h.addAssistantMessage({
					success: false,
					error: error
				});
			});
	}

	function alertMissingOrderSelection() {
		window.alert('Please select at least one order.');
	}

	function renderOrderDetailsResult(data, h) {
		const result = data.result || {};
		const orders = Array.isArray(result.orders) ? result.orders : [];
		const parsed = data.parsed || {};

		let html = '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || ('Order details: ' + orders.length)) + '</strong>';

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '</div>';

		html += renderSupportFlowPanel(result, h);

		html += '<div class="yoro-order-detail-grid' + (orders.length > 1 ? ' yoro-order-detail-grid-stacked' : '') + '">';

		orders.forEach(function(order) {
			const customerName = order.customer_name || [
				order.billing_first_name || '',
				order.billing_last_name || ''
			].join(' ').trim();
			const billingAddress = [
				order.billing_company || '',
				order.billing_address_1 || '',
				order.billing_address_2 || '',
				[order.billing_city, order.billing_state, order.billing_postcode].filter(Boolean).join(', '),
				order.billing_country || ''
			].filter(Boolean);
			const shippingAddress = [
				[order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ').trim(),
				order.shipping_company || '',
				order.shipping_address_1 || '',
				order.shipping_address_2 || '',
				[order.shipping_city, order.shipping_state, order.shipping_postcode].filter(Boolean).join(', '),
				order.shipping_country || ''
			].filter(Boolean);
			const totals = order.totals || {};
			const lineItems = Array.isArray(order.line_items) ? order.line_items : [];
			const notes = Array.isArray(order.notes) ? order.notes : [];
			const currency = order.currency || '';
			const signalItems = [
				{ label: 'Requires shipping', value: order.requires_shipping ? 'Yes' : 'No' },
				{ label: 'Tracking detected', value: order.tracking_detected ? 'Yes' : 'No' },
				{ label: 'Shipping complete', value: order.shipping_complete ? 'Yes' : 'No' },
				{ label: 'Ready to fulfill', value: order.ready_to_fulfill ? 'Yes' : 'No' }
			];

			html += '<div class="yoro-order-detail-card">';

			html += '<div class="yoro-order-detail-header">';
			html += '<div>';
			html += '<strong class="yoro-order-detail-title">Order #' + h.escapeHtml(order.number || order.id) + '</strong>';
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(formatDateTime(order.date_created, order.date_created_ts, h)) + ' · ' + h.escapeHtml(formatAgeHours(order.age_hours) + ' old') + '</div>';
			html += '</div>';
			html += '<span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span>';
			html += '</div>';

			html += '<div class="yoro-order-detail-metrics yoro-order-detail-metrics-compact">';
			html += '<div><span>Total</span><strong>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div><span>Items</span><strong>' + h.escapeHtml(order.item_count || 0) + '</strong></div>';
			html += '<div><span>Payment</span><strong>' + h.escapeHtml(order.payment_method_title || order.payment_method || '—') + '</strong></div>';
			html += '<div><span>Shipping</span><strong>' + h.escapeHtml(order.shipping_method || (order.requires_shipping ? 'Missing' : 'Not needed')) + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-flags">';
			signalItems.forEach(function(signal) {
				html += '<span class="yoro-order-detail-flag"><strong>' + h.escapeHtml(signal.label) + ':</strong> ' + h.escapeHtml(signal.value) + '</span>';
			});
			html += '</div>';

			html += '<div class="yoro-order-detail-stack">';
			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Customer</h4>';
			html += '<div class="yoro-order-detail-kv"><span>Name</span><strong>' + h.escapeHtml(customerName || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Email</span><strong>' + h.escapeHtml(order.billing_email || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Phone</span><strong>' + h.escapeHtml(order.billing_phone || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Customer ID</span><strong>' + h.escapeHtml(order.customer_id || 'Guest') + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Payment & fulfillment</h4>';
			html += '<div class="yoro-order-detail-kv"><span>Payment</span><strong>' + h.escapeHtml(order.payment_method_title || order.payment_method || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Transaction</span><strong>' + h.escapeHtml(order.transaction_id || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Shipping method</span><strong>' + h.escapeHtml(order.shipping_method || (order.requires_shipping ? 'Missing' : 'Not needed')) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Paid</span><strong>' + h.escapeHtml(formatDateTime(order.date_paid, order.date_paid_ts, h) || '—') + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Totals</h4>';
			html += '<div class="yoro-order-detail-kv"><span>Subtotal</span><strong>' + h.escapeHtml(totals.subtotal || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Discount</span><strong>' + h.escapeHtml(totals.discount_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Shipping</span><strong>' + h.escapeHtml(totals.shipping_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Tax</span><strong>' + h.escapeHtml(totals.tax_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Refunded</span><strong>' + h.escapeHtml(totals.refunded_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Remaining</span><strong>' + h.escapeHtml(totals.remaining_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Addresses</h4>';
			html += '<div class="yoro-order-detail-kv yoro-order-detail-kv-block"><span>Billing</span><strong>' + h.escapeHtml(billingAddress.join(', ') || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv yoro-order-detail-kv-block"><span>Shipping</span><strong>' + h.escapeHtml(shippingAddress.join(', ') || '—') + '</strong></div>';
			html += '</div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-section yoro-order-detail-section-spacious">';
			html += '<h4>Line items</h4>';
			if (lineItems.length) {
				html += '<ul class="yoro-order-detail-list">';
				lineItems.forEach(function(item) {
					const itemMeta = [
						item.sku ? ('SKU ' + item.sku) : '',
						(item.quantity || 0) ? ('Qty ' + item.quantity) : '',
						item.virtual ? 'Virtual' : '',
						item.downloadable ? 'Downloadable' : ''
					].filter(Boolean).join(' · ');
					html += '<li><strong>' + h.escapeHtml(item.name || 'Item') + '</strong>';
					html += '<div class="yoro-result-subtle">' + h.escapeHtml(itemMeta || '—') + '</div>';
					html += '<div class="yoro-result-subtle">Line total: ' + h.escapeHtml(item.total || '0') + ' ' + h.escapeHtml(currency) + '</div></li>';
				});
				html += '</ul>';
			} else {
				html += '<p>—</p>';
			}
			html += '</div>';

			if (order.customer_note || notes.length) {
				html += '<div class="yoro-order-detail-section yoro-order-detail-section-spacious">';
				html += '<h4>Notes</h4>';
				if (order.customer_note) {
					html += '<p><strong>Customer note:</strong> ' + h.escapeHtml(order.customer_note) + '</p>';
				}
				if (notes.length) {
					html += '<ul class="yoro-order-detail-list">';
					notes.forEach(function(note) {
						const noteMeta = [
							formatDateTime(note.date, note.date_ts, h) || '',
							note.customer ? 'Customer note' : 'Internal note',
							note.author || '',
							note.via_yoro ? 'via YoRo' : ''
						].filter(Boolean).join(' · ');
						html += '<li><strong>' + h.escapeHtml(noteMeta || 'Note') + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(note.content || '') + '</div></li>';
					});
					html += '</ul>';
				}
				html += '</div>';
			}

			if (order.date_paid || order.date_completed) {
				html += '<div class="yoro-order-detail-section yoro-order-detail-section-spacious">';
				html += '<h4>Milestones</h4>';
				html += '<p><strong>Paid:</strong> ' + h.escapeHtml(formatDateTime(order.date_paid, order.date_paid_ts, h) || '—') + '</p>';
				html += '<p><strong>Completed:</strong> ' + h.escapeHtml(formatDateTime(order.date_completed, order.date_completed_ts, h) || '—') + '</p>';
				html += '</div>';
			}

			html += '<div class="yoro-order-detail-actions">';
			html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '" data-action-scope="detail" data-has-email-drafts="' + (order.has_email_drafts ? '1' : '0') + '" data-draft-count="' + h.escapeHtml(order.draft_count || 0) + '">Actions</button>';
			html += '<button type="button" class="button button-small yoro-row-run-hold" data-order-id="' + h.escapeHtml(order.id) + '">Hold</button>';
			html += '<button type="button" class="button button-small yoro-row-update-status" data-order-id="' + h.escapeHtml(order.id) + '">Change status</button>';
			html += '<button type="button" class="button button-small yoro-row-add-note" data-order-id="' + h.escapeHtml(order.id) + '">Add note</button>';
			html += '<button type="button" class="button button-small yoro-row-write-email" data-order-id="' + h.escapeHtml(order.id) + '">Write email</button>';

			if (order.edit_url) {
				html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open order</a>';
			}

			html += '</div>';

			html += '</div>';
		});

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}	

	function renderAssistantIntro(result, h, locale) {
		if (!result || (!result.assistant_summary && !result.assistant_followup && !(result.meta && result.meta.assumption))) {
			return '';
		}

		locale = normalizeLocale(locale || '');
		if (locale && ['en', 'vi'].indexOf(locale) === -1) {
			return '';
		}

		const assumption = result.meta && result.meta.assumption ? result.meta.assumption : null;
		let html = '<div class="yoro-result-notice yoro-result-success">';

		if (result.assistant_summary && result.assistant_summary !== result.summary) {
			html += '<p>' + h.escapeHtml(result.assistant_summary) + '</p>';
		}

		if (assumption && assumption.message) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(assumption.message) + '</p>';
		}

		if (result.assistant_followup) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(result.assistant_followup) + '</p>';
		}

		html += '</div>';

		return html;
	}

	function renderAdminCopilotBoundaryCard(result, h) {
		return '';
	}

	function renderOrdersResult(data, h) {
		const result = data.result || {};
		const orders = Array.isArray(result.orders) ? result.orders : [];
		const parsed = data.parsed || {};
		const action = parsed.action || result.action || '';
		const locale = getResultLocale(data);
		const firstOrder = orders.length ? orders[0] : null;
		const contextMeta = getOrdersListContextMeta(result, parsed, orders, locale);
		const needsAttentionCount = orders.filter(function(order) {
			return ['pending', 'on-hold', 'failed'].indexOf(String(order && order.status ? order.status : '')) !== -1;
		}).length;
		const combinedTotal = orders.reduce(function(sum, order) {
			return sum + (parseFloat(order && order.total ? order.total : 0) || 0);
		}, 0);
		const averageAge = orders.length
			? Math.round(orders.reduce(function(sum, order) {
				return sum + (parseInt(order && order.age_hours ? order.age_hours : 0, 10) || 0);
			}, 0) / orders.length)
			: 0;

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || contextMeta.summary || ('Found ' + (result.count || orders.length) + ' orders')) + '</strong>';
		html += '<span class="yoro-result-subtle">' + h.escapeHtml(contextMeta.subtle) + '</span>';

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'decisionRoute', 'Decision route')) + ': ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		if (typeof parsed.confidence !== 'undefined' && action === 'orders.find_by_identity') {
			html += '<span class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'confidence', 'Confidence')) + ': ' + h.escapeHtml(Math.round(parsed.confidence * 100)) + '%</span>';
		}

		html += '</div>';

		html += renderSupportFlowPanel(result, h);

		if (!orders.length) {
			html += renderOrdersEmptyState(result, parsed, contextMeta, h);
			html += h.renderDebugJson(data);

			return html;
		}

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(contextMeta.title) + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(contextMeta.meta) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(orderCountLabel(result.count || orders.length, locale)) + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics yoro-order-detail-metrics-compact">';
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'orders', 'Orders')) + '</span><strong>' + h.escapeHtml(String(result.count || orders.length)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(contextMeta.metricSubtle) + '</small></div>';
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'needsAttention', 'Needs attention')) + '</span><strong>' + h.escapeHtml(String(needsAttentionCount)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'needsAttentionSubtle', 'Pending, on-hold, or failed')) + '</small></div>';
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'combinedTotal', 'Combined total')) + '</span><strong>' + h.escapeHtml(combinedTotal.toFixed(2) + ' ' + getOrdersListCurrency(orders)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'combinedTotalSubtle', 'Across visible results')) + '</small></div>';
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'averageAge', 'Average age')) + '</span><strong>' + h.escapeHtml(formatAgeHoursForLocale(averageAge, locale)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'visibleResults', 'Visible results')) + '</small></div>';
		html += '</div>';

		html += '<div class="yoro-bulk-actions">';
		html += '<button type="button" class="button yoro-run-hold">' + h.escapeHtml(orderUiText(locale, 'hold', 'Hold')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-details">' + h.escapeHtml(orderUiText(locale, 'viewDetails', 'View details')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-add-note">' + h.escapeHtml(orderUiText(locale, 'addNote', 'Add note')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-update-status">' + h.escapeHtml(orderUiText(locale, 'updateStatus', 'Update status')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-status-note">' + h.escapeHtml(orderUiText(locale, 'statusNote', 'Status + Note')) + '</button>';
		html += '</div>';

		html += '<table class="yoro-orders-table">';
		html += '<thead>';
		html += '<tr>';
		html += '<th><input type="checkbox" class="yoro-select-all"></th>';
		html += '<th>' + h.escapeHtml(orderUiText(locale, 'order', 'Order')) + '</th>';
		html += '<th>' + h.escapeHtml(orderUiText(locale, 'customer', 'Customer')) + '</th>';
		html += '<th>' + h.escapeHtml(orderUiText(locale, 'case', 'Case')) + '</th>';
		html += '<th>' + h.escapeHtml(orderUiText(locale, 'total', 'Total')) + '</th>';
		html += '<th>' + h.escapeHtml(orderUiText(locale, 'age', 'Age')) + '</th>';
		html += '<th>' + h.escapeHtml(orderUiText(locale, 'nextAction', 'Next action')) + '</th>';
		html += '</tr>';
		html += '</thead>';
		html += '<tbody>';

		orders.forEach(function (order) {
			const customerLabel = order.customer_name || order.billing_email || order.billing_phone || orderUiText(locale, 'customerFallback', 'Customer');
			const caseLabel = buildOrdersListCaseLabel(order, action, locale);
			const nextActionLabel = action === 'orders.find_by_identity'
				? orderUiText(locale, 'compareDetails', 'Compare details')
				: orderUiText(locale, 'viewDetails', 'Open details');
			html += '<tr>';
			html += '<td><input type="checkbox" class="yoro-order-checkbox" value="' + h.escapeHtml(order.id) + '"></td>';
			html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
			html += '<td><strong>' + h.escapeHtml(customerLabel) + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(order.billing_email || order.billing_phone || '—') + '</div></td>';
			html += '<td><span class="yoro-status-badge">' + h.escapeHtml(orderStatusLabel(order.status || '', locale)) + '</span><div class="yoro-result-subtle">' + h.escapeHtml(caseLabel) + '</div></td>';
			html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
			html += '<td>' + h.escapeHtml(formatAgeHoursForLocale(order.age_hours, locale)) + '<div class="yoro-result-subtle">' + h.escapeHtml(formatDateTime(order.date_created, order.date_created_ts, h)) + '</div></td>';
			html += '<td class="yoro-row-actions">';
			html += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h.escapeHtml(order.id) + '">' + h.escapeHtml(nextActionLabel) + '</button> ';
			html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '" data-action-scope="row" data-has-email-drafts="' + (order.has_email_drafts ? '1' : '0') + '" data-draft-count="' + h.escapeHtml(order.draft_count || 0) + '">' + h.escapeHtml(orderUiText(locale, 'actions', 'Actions')) + '</button> ';

			if (order.edit_url) {
				html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">' + h.escapeHtml(orderUiText(locale, 'open', 'Open')) + '</a>';
			}

			html += '</td>';
			html += '</tr>';
		});

		html += '</tbody>';
		html += '</table>';
		html += renderQueueActionStrip([
			firstOrder ? { label: orderUiText(locale, 'showFirstOrder', 'Show first order'), command: 'Show order #' + (firstOrder.number || firstOrder.id) + ' details' } : null,
			action === 'orders.find_by_customer' && (result.email || result.phone)
				? { label: orderUiText(locale, 'customerSummary', 'Customer summary'), command: buildCustomerSummaryCommand(result) }
				: null,
			action === 'orders.find_by_identity' && result.order_id
				? { label: orderUiText(locale, 'showSourceOrder', 'Show source order'), command: 'Show order #' + result.order_id + ' details' }
				: null,
			{ label: orderUiText(locale, 'latestFiveOrders', 'Latest 5 orders'), command: 'Show latest 5 orders' }
		].filter(Boolean), h, orderUiText(locale, 'whatNext', 'What to do next'));
		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}

	function renderOrdersEmptyState(result, parsed, contextMeta, h) {
		const locale = parsed && parsed.language ? parsed.language : 'en';
		const emptyState = result && result.empty_state && typeof result.empty_state === 'object'
			? result.empty_state
			: {};
		const title = emptyState.title || (isVietnamese(locale) ? 'Không tìm thấy đơn phù hợp.' : 'No matching orders found.');
		const description = emptyState.description || (isVietnamese(locale) ? 'Không có đơn nào khớp với yêu cầu này.' : 'No orders matched this request.');
		const actions = Array.isArray(emptyState.actions) ? emptyState.actions : [];
		let html = '<div class="yoro-order-detail-card yoro-empty-orders-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(title) + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(contextMeta.meta || orderUiText(locale, 'noVisibleOrders', 'No visible orders')) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(orderCountLabel(0, locale)) + '</span>';
		html += '</div>';
		html += '<p>' + h.escapeHtml(description) + '</p>';
		html += '<p class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'tryWiderFilters', 'Try widening the filters or checking a broader recent order list.')) + '</p>';

		if (actions.length) {
			html += renderQueueActionStrip(actions.filter(function(action) {
				return action && action.label && action.command;
			}), h, orderUiText(locale, 'tryNext', 'Try next'));
		}

		html += '</div>';

		return html;
	}

	function getOrdersListContextMeta(result, parsed, orders, locale) {
		const action = parsed.action || result.action || '';
		if (action === 'orders.find_by_customer') {
			const target = result.email || result.phone || (isVietnamese(locale) ? 'khách hàng này' : 'this customer');
			return {
				title: isVietnamese(locale) ? 'Lịch sử đơn hàng của khách' : 'Customer order history',
				meta: isVietnamese(locale) ? ('Đơn liên quan đến ' + target) : ('Orders linked to ' + target),
				summary: result.summary || (isVietnamese(locale) ? ('Tìm thấy ' + orders.length + ' đơn của khách hàng.') : ('Found ' + orders.length + ' customer orders.')),
				subtle: isVietnamese(locale) ? 'Lịch sử đơn gần đây của khách được liệt kê bên dưới.' : 'Recent customer history is listed below.',
				metricSubtle: isVietnamese(locale) ? 'Liên quan đến cùng khách hàng' : 'Linked to the same customer'
			};
		}

		if (action === 'orders.find_by_identity') {
			return {
				title: isVietnamese(locale) ? 'Đơn liên quan theo định danh' : 'Related orders by identity',
				meta: isVietnamese(locale) ? ('So sánh với đơn #' + (result.order_id || '')) : ('Compared against order #' + (result.order_id || '')),
				summary: result.summary || (isVietnamese(locale) ? ('Tìm thấy ' + orders.length + ' đơn khớp định danh.') : ('Found ' + orders.length + ' identity-matched orders.')),
				subtle: isVietnamese(locale) ? 'Các đơn này có tín hiệu khách hàng hoặc định danh giống nhau.' : 'These orders share customer or identity signals.',
				metricSubtle: isVietnamese(locale) ? 'Khớp theo tín hiệu định danh' : 'Matched by identity signals'
			};
		}

			return {
				title: orderUiText(locale, 'orders', isVietnamese(locale) ? 'Đơn hàng' : 'Orders'),
				meta: orderUiText(locale, 'recentMatching', isVietnamese(locale) ? 'Các đơn phù hợp gần đây' : 'Most recent matching orders'),
				summary: result.summary || (isVietnamese(locale) ? ('Tìm thấy ' + orders.length + ' đơn.') : ('Found ' + orders.length + ' orders.')),
				subtle: orderUiText(locale, 'recentMatchingSubtle', isVietnamese(locale) ? 'Các đơn phù hợp gần đây được liệt kê bên dưới.' : 'The most recent matching orders are listed below.'),
				metricSubtle: orderUiText(locale, 'visibleResults', isVietnamese(locale) ? 'Hiển thị trong danh sách này' : 'Visible in this list')
			};
		}

	function buildOrdersListCaseLabel(order, action, locale) {
		if (action === 'orders.find_by_identity') {
			const reasons = Array.isArray(order.match_reasons) ? order.match_reasons : [];
			if (reasons.length) {
				return isVietnamese(locale)
					? 'Khớp theo ' + reasons.join(', ')
					: 'Matched by ' + reasons.join(', ');
			}
		}

		return paymentMethodLabel(order.payment_method_title || order.payment_method || '', locale)
			|| orderUiText(locale, 'orderReview', 'Order review');
	}

	function getOrdersListCurrency(orders) {
		const first = Array.isArray(orders) && orders.length ? orders[0] : null;
		return first && first.currency ? first.currency : '';
	}

	function buildCustomerSummaryCommand(result) {
		if (result && result.email) {
			return 'Show customer summary for ' + result.email;
		}
		if (result && result.phone) {
			return 'Show customer summary for ' + result.phone;
		}
		return 'Show customer summary';
	}

	function renderCustomerSummaryResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const orders = Array.isArray(result.orders) ? result.orders : [];
		const statusCounts = result.status_counts || {};
		const paymentMethods = result.payment_methods || {};
		const latestOrderId = result.latest_order_id || 0;
		const latestOrderNo = result.latest_order_no || latestOrderId;
		const customerLabel = result.customer_name || result.email || result.phone || 'Customer summary';

		function labelize(key) {
			return String(key || '')
				.replace(/_/g, ' ')
				.replace(/\b\w/g, function(char) {
					return char.toUpperCase();
				});
		}

		function renderStatusMix(counts) {
			const entries = Object.keys(counts || {});

			if (!entries.length) {
				return '<p class="yoro-result-subtle">No order status history yet.</p>';
			}

			let html = '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Status</th><th>Orders</th></tr></thead><tbody>';
			entries.forEach(function(key) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
				html += '<td>' + h.escapeHtml(counts[key] || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			return html;
		}

		function renderPaymentMix(methods) {
			const entries = Object.keys(methods || {});

			if (!entries.length) {
				return '<p class="yoro-result-subtle">No payment method history yet.</p>';
			}

			let html = '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Payment method</th><th>Orders</th></tr></thead><tbody>';
			entries.forEach(function(key) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(key || '—') + '</td>';
				html += '<td>' + h.escapeHtml(methods[key] || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			return html;
		}

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || ('Found ' + orders.length + ' orders for this customer.')) + '</strong>';

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Customer summary</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(customerLabel) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(result.count || orders.length) + ' orders</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Total spent</span><strong>' + h.escapeHtml(result.total_spent || '0.00') + ' ' + h.escapeHtml(result.currency || '') + '</strong></div>';
		html += '<div><span>Orders</span><strong>' + h.escapeHtml(result.count || orders.length) + '</strong></div>';
		html += '<div><span>First order</span><strong>' + h.escapeHtml(formatDateTime(result.first_order_date, result.first_order_date_ts, h) || '—') + '</strong></div>';
		html += '<div><span>Last order</span><strong>' + h.escapeHtml(formatDateTime(result.last_order_date, result.last_order_date_ts, h) || '—') + '</strong></div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Customer snapshot</h4>';
		html += '<div class="yoro-order-detail-columns">';
		html += '<div>';
		html += '<p><strong>Name:</strong> ' + h.escapeHtml(result.customer_name || '—') + '</p>';
		html += '<p><strong>Email:</strong> ' + h.escapeHtml(result.email || '—') + '</p>';
		html += '</div>';
		html += '<div>';
		html += '<p><strong>Phone:</strong> ' + h.escapeHtml(result.phone || '—') + '</p>';
		html += '<p><strong>Customer ID:</strong> ' + h.escapeHtml(result.customer_id || 'Guest') + '</p>';
		html += '</div>';
		html += '</div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Status mix</h4>';
		html += renderStatusMix(statusCounts);
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Payment methods</h4>';
		html += renderPaymentMix(paymentMethods);
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Recent orders</h4>';

		if (!orders.length) {
			html += '<p class="yoro-result-subtle">No recent orders found for this customer.</p>';
		} else {
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Payment</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
			orders.slice(0, 8).forEach(function(order) {
				html += '<tr>';
				html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span></td>';
				html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.payment_method || '—') + '</td>';
				html += '<td>' + h.escapeHtml(formatDateTime(order.date_created, order.date_created_ts, h) || '—') + '</td>';
				html += '<td class="yoro-row-actions">';
				html += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h.escapeHtml(order.id) + '">Details</button> ';
				if (order.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
				}
				html += '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
		}

		html += '</div>';

		html += renderQueueActionStrip([
			latestOrderId ? { label: 'Show latest order', command: 'Show order #' + latestOrderNo + ' details' } : null,
			{ label: 'Customer service', command: 'Show customer service queue' },
			{ label: 'Latest 5 orders', command: 'Show latest 5 orders' }
		].filter(Boolean), h, 'What to do next');

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderSupportFlowPanel(result, h) {
		return '';
	}

		function renderOperationsSummaryResult(data, h) {
			const result = data.result || {};
			const parsed = data.parsed || {};
			const locale = getResultLocale(data);
			const windowInfo = result.window || {};
			const recentOrders = Array.isArray(result.recent_orders) ? result.recent_orders : [];
			const windowStatusCounts = result.window_status_counts || {};
			const backlogCounts = result.backlog_counts || {};
			const attentionCounts = result.attention_counts || {};

			function labelize(key) {
				return queueLabel(key, locale);
			}

			let html = renderAssistantIntro(result, h, locale);
			html += '<div class="yoro-result-summary">';
			html += '<strong>' + h.escapeHtml(localizedOperationsSummary(result, locale)) + '</strong>';

			if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
				html += '<span class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'decisionRoute', 'Decision route')) + ': ' + h.escapeHtml(parsed.parser_rule) + '</span>';
			}

			if (windowInfo.label) {
				html += '<span class="yoro-result-subtle">' + h.escapeHtml(operationalUiText(locale, 'window', 'Window')) + ': ' + h.escapeHtml(localizedWindowLabel(windowInfo, locale)) + '</span>';
			}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
			html += '<div class="yoro-order-detail-header">';
			html += '<div>';
			html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(operationalUiText(locale, 'storeOperations', 'Store operations')) + '</strong>';
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(formatWindowRange(windowInfo, h)) + '</div>';
			html += '</div>';
			html += '<span class="yoro-current-order-badge">' + h.escapeHtml(localizedFocusLabel(result.focus, locale, operationalUiText)) + '</span>';
			html += '</div>';

			html += '<div class="yoro-order-detail-metrics">';
			html += '<div><span>' + h.escapeHtml(operationalUiText(locale, 'newOrders', 'New orders')) + '</span><strong>' + h.escapeHtml(result.window_order_count || 0) + '</strong></div>';
			html += '<div><span>' + h.escapeHtml(operationalUiText(locale, 'openBacklog', 'Open backlog')) + '</span><strong>' + h.escapeHtml(result.backlog_total || 0) + '</strong></div>';
			html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'needsAttention', 'Needs attention')) + '</span><strong>' + h.escapeHtml(result.attention_total || 0) + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(operationalUiText(locale, 'ordersInWindow', 'Orders in window')) + '</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>' + h.escapeHtml(operationalUiText(locale, 'status', 'Status')) + '</th><th>' + h.escapeHtml(operationalUiText(locale, 'count', 'Count')) + '</th></tr></thead><tbody>';
		Object.keys(windowStatusCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(windowStatusCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
			html += '</div>';

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(operationalUiText(locale, 'currentBacklog', 'Current backlog')) + '</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>' + h.escapeHtml(operationalUiText(locale, 'queue', 'Queue')) + '</th><th>' + h.escapeHtml(operationalUiText(locale, 'count', 'Count')) + '</th></tr></thead><tbody>';
		Object.keys(backlogCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(backlogCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
			html += '</div>';

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(operationalUiText(locale, 'agedQueues', 'Aged queues')) + '</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>' + h.escapeHtml(operationalUiText(locale, 'queue', 'Queue')) + '</th><th>' + h.escapeHtml(operationalUiText(locale, 'count', 'Count')) + '</th></tr></thead><tbody>';
		Object.keys(attentionCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(attentionCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		html += '</div>';

			if (recentOrders.length) {
				html += '<div class="yoro-order-detail-section">';
				html += '<h4>' + h.escapeHtml(operationalUiText(locale, 'recentOpenOrders', 'Recent open orders')) + '</h4>';
				html += '<table class="yoro-orders-table">';
				html += '<thead><tr><th>' + h.escapeHtml(orderUiText(locale, 'order', 'Order')) + '</th><th>' + h.escapeHtml(operationalUiText(locale, 'status', 'Status')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'total', 'Total')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'email', 'Email')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'date', 'Date')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'actions', 'Actions')) + '</th></tr></thead><tbody>';

			recentOrders.forEach(function(order) {
				html += '<tr>';
				html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
					html += '<td><span class="yoro-status-badge">' + h.escapeHtml(orderStatusLabel(order.status || '', locale)) + '</span></td>';
				html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.billing_email || '') + '</td>';
				html += '<td>' + h.escapeHtml(formatDateTime(order.date_created, order.date_created_ts, h)) + '</td>';
				html += '<td class="yoro-row-actions">';
					html += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h.escapeHtml(order.id) + '">' + h.escapeHtml(operationalUiText(locale, 'details', 'Details')) + '</button> ';
					html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '" data-action-scope="row" data-has-email-drafts="' + (order.has_email_drafts ? '1' : '0') + '" data-draft-count="' + h.escapeHtml(order.draft_count || 0) + '">' + h.escapeHtml(orderUiText(locale, 'actions', 'Actions')) + '</button> ';

					if (order.edit_url) {
						html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">' + h.escapeHtml(orderUiText(locale, 'open', 'Open')) + '</a>';
					}

				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		}

			html += '<div class="yoro-suggestions">';
			html += '<strong>' + h.escapeHtml(operationalUiText(locale, 'suggestedNextChecks', 'Suggested next checks')) + '</strong>';
			html += '<div class="yoro-suggestion-list">';
			html += renderDirectActionButton(operationalUiText(locale, 'pendingOrders', 'Pending orders'), 'Show pending orders', h);
			html += renderDirectActionButton(operationalUiText(locale, 'processingOrders', 'Processing orders'), 'Show processing orders', h);
			html += renderDirectActionButton(operationalUiText(locale, 'onHoldOrders', 'On-hold orders'), 'Show on-hold orders', h);
		html += '</div>';
		html += '</div>';

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

		function renderBusinessSummaryResult(data, h) {
			const result = data.result || {};
			const parsed = data.parsed || {};
			const locale = getResultLocale(data);
			const windowInfo = result.window || {};
			const metrics = result.business_metrics || {};
		const businessContext = result.business_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const statusCounts = result.order_status_counts || {};
		const topCustomers = Array.isArray(result.top_customers) ? result.top_customers : [];
		const topProducts = Array.isArray(result.top_products) ? result.top_products : [];
		const recentOrders = Array.isArray(result.recent_orders) ? result.recent_orders : [];
			const topPriority = priorityItems.length ? priorityItems[0] : null;
			const mainSignalLabel = humanizeBusinessTopic(businessContext.top_topic || 'overview');
			const startHere = buildBriefingStartHereFromSequence(recommendedSequence);
			const supportingPriorityItems = priorityItems.slice(1, 4);
			const showNarrativeCards = ['en', 'vi', ''].indexOf(normalizeLocale(locale)) !== -1;

		function labelize(key) {
			return String(key || '')
				.replace(/_/g, ' ')
				.replace(/\b\w/g, function(char) {
					return char.toUpperCase();
				});
		}

			let html = renderAssistantIntro(result, h, locale);
			html += '<div class="yoro-result-summary">';
			html += '<strong>' + h.escapeHtml(businessUiText(locale, 'businessSummary', 'Business summary')) + '</strong>';
			html += '<span class="yoro-result-subtle">' + h.escapeHtml(businessUiText(locale, 'readOnly', 'Read-only executive summary. No store data is changed.')) + '</span>';

			if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
				html += '<span class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'decisionRoute', 'Decision route')) + ': ' + h.escapeHtml(parsed.parser_rule) + '</span>';
			}

			if (windowInfo.label) {
				html += '<span class="yoro-result-subtle">' + h.escapeHtml(operationalUiText(locale, 'window', 'Window')) + ': ' + h.escapeHtml(localizedWindowLabel(windowInfo, locale)) + '</span>';
			}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
			html += '<div class="yoro-order-detail-header">';
			html += '<div>';
			html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(businessUiText(locale, 'businessOverview', 'Business overview')) + '</strong>';
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(formatWindowRange(windowInfo, h)) + '</div>';
			html += '</div>';
			html += '<span class="yoro-current-order-badge">' + h.escapeHtml(localizedFocusLabel(result.focus, locale, businessUiText)) + '</span>';
			html += '</div>';

			html += '<div class="yoro-result-summary">';
			html += '<strong>' + h.escapeHtml(localizedBusinessSummary(result, locale)) + '</strong>';
			html += '</div>';

			if (!showNarrativeCards) {
				html += renderQueueMetaMetrics([
					{ label: orderUiText(locale, 'orders', 'Orders'), value: metrics.order_count || 0 },
					{ label: businessUiText(locale, 'grossSales', 'Gross sales'), value: metrics.gross_sales || 0 },
					{ label: businessUiText(locale, 'customers', 'Customers'), value: metrics.unique_customers || 0 },
					{ label: businessUiText(locale, 'attention', 'Attention'), value: result.attention_total || 0 }
				], h);
			} else if (topPriority) {
				html += renderQueueWorkbenchHero(topPriority, [
					{ label: orderUiText(locale, 'orders', 'Orders'), value: metrics.order_count || 0 },
					{ label: businessUiText(locale, 'grossSales', 'Gross sales'), value: metrics.gross_sales || 0 },
					{ label: businessUiText(locale, 'customers', 'Customers'), value: metrics.unique_customers || 0 },
					{ label: businessUiText(locale, 'attention', 'Attention'), value: result.attention_total || 0 },
					{ label: businessUiText(locale, 'mainSignal', 'Main signal'), value: mainSignalLabel }
				], h);
			}

			if (showNarrativeCards && startHere) {
				html += renderQueueStartHereCard(startHere, h);
			}
			if (showNarrativeCards) {
				html += renderWorklistModeCard(result, h);
			}

			if (showNarrativeCards && supportingPriorityItems.length) {
				html += renderQueuePriorityCards(supportingPriorityItems, h, businessUiText(locale, 'otherPressures', 'Other pressures to watch'));
			}

			html += '<details class="yoro-order-detail-section yoro-briefing-disclosure">';
			html += '<summary><strong>' + h.escapeHtml(businessUiText(locale, 'seeDetailView', 'See detail view')) + '</strong><span>' + h.escapeHtml(businessUiText(locale, 'seeDetailSubtitle', 'Open commercial detail, order mix, and top performers')) + '</span></summary>';
			html += '<div class="yoro-briefing-disclosure-body">';
			html += renderQueueMetaMetrics([
				{ label: orderUiText(locale, 'orders', 'Orders'), value: metrics.order_count || 0 },
				{ label: businessUiText(locale, 'grossSales', 'Gross sales'), value: metrics.gross_sales || 0 },
				{ label: businessUiText(locale, 'aov', 'AOV'), value: metrics.average_order_value || 0 },
				{ label: businessUiText(locale, 'customers', 'Customers'), value: metrics.unique_customers || 0 },
				{ label: businessUiText(locale, 'productLines', 'Product lines'), value: metrics.product_lines || 0 },
				{ label: businessUiText(locale, 'backlog', 'Backlog'), value: result.backlog_total || 0 }
			], h);

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(businessUiText(locale, 'orderMix', 'Order mix')) + '</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>' + h.escapeHtml(operationalUiText(locale, 'status', 'Status')) + '</th><th>' + h.escapeHtml(operationalUiText(locale, 'count', 'Count')) + '</th></tr></thead><tbody>';
			Object.keys(statusCounts).forEach(function(key) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(queueLabel(key, locale)) + '</td>';
				html += '<td>' + h.escapeHtml(statusCounts[key] || 0) + '</td>';
				html += '</tr>';
			});
		html += '</tbody></table>';
		html += '</div>';

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(businessUiText(locale, 'operatingPressure', 'Operating pressure')) + '</h4>';
			html += '<div class="yoro-order-detail-metrics">';
			html += '<div><span>' + h.escapeHtml(businessUiText(locale, 'backlog', 'Backlog')) + '</span><strong>' + h.escapeHtml(result.backlog_total || 0) + '</strong></div>';
			html += '<div><span>' + h.escapeHtml(businessUiText(locale, 'attentionItems', 'Attention items')) + '</span><strong>' + h.escapeHtml(result.attention_total || 0) + '</strong></div>';
			html += '<div><span>' + h.escapeHtml(businessUiText(locale, 'completedSales', 'Completed sales')) + '</span><strong>' + h.escapeHtml(metrics.completed_sales || 0) + '</strong></div>';
			html += '</div>';
			html += '</div>';

			if (topCustomers.length) {
				html += '<div class="yoro-order-detail-section">';
				html += '<h4>' + h.escapeHtml(businessUiText(locale, 'topCustomers', 'Top customers')) + '</h4>';
				html += '<table class="yoro-orders-table">';
				html += '<thead><tr><th>' + h.escapeHtml(orderUiText(locale, 'customer', 'Customer')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'email', 'Email')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'orders', 'Orders')) + '</th><th>' + h.escapeHtml(businessUiText(locale, 'totalSpent', 'Total spent')) + '</th></tr></thead><tbody>';
			topCustomers.forEach(function(customer) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(customer.name || '—') + '</td>';
				html += '<td>' + h.escapeHtml(customer.email || '') + '</td>';
				html += '<td>' + h.escapeHtml(customer.order_count || 0) + '</td>';
				html += '<td>' + h.escapeHtml(customer.total_spent || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
		}

			if (topProducts.length) {
				html += '<div class="yoro-order-detail-section">';
				html += '<h4>' + h.escapeHtml(businessUiText(locale, 'topProducts', 'Top products')) + '</h4>';
				html += '<table class="yoro-orders-table">';
				html += '<thead><tr><th>' + h.escapeHtml(businessUiText(locale, 'product', 'Product')) + '</th><th>' + h.escapeHtml(businessUiText(locale, 'qty', 'Qty')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'total', 'Total')) + '</th></tr></thead><tbody>';
			topProducts.forEach(function(product) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(product.name || ('#' + product.id)) + '</td>';
				html += '<td>' + h.escapeHtml(product.quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(product.total || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
		}

			if (recentOrders.length) {
				html += '<div class="yoro-order-detail-section">';
				html += '<h4>' + h.escapeHtml(businessUiText(locale, 'recentOrders', 'Recent orders')) + '</h4>';
				html += '<table class="yoro-orders-table">';
				html += '<thead><tr><th>' + h.escapeHtml(orderUiText(locale, 'order', 'Order')) + '</th><th>' + h.escapeHtml(operationalUiText(locale, 'status', 'Status')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'total', 'Total')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'email', 'Email')) + '</th><th>' + h.escapeHtml(orderUiText(locale, 'date', 'Date')) + '</th></tr></thead><tbody>';
				recentOrders.forEach(function(order) {
					html += '<tr>';
					html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
					html += '<td><span class="yoro-status-badge">' + h.escapeHtml(orderStatusLabel(order.status || '', locale)) + '</span></td>';
				html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.billing_email || '') + '</td>';
				html += '<td>' + h.escapeHtml(formatDateTime(order.date_created, order.date_created_ts, h)) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
		}

		html += '</div>';
		html += '</details>';
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderMorningBriefingResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const yesterdaySnapshot = result.yesterday_snapshot || {};
		const livePressure = result.live_pressure || {};
		const topPriorities = Array.isArray(result.top_priorities) ? result.top_priorities : [];
		const firstAction = result.first_recommended_action || {};
		const priorityDrilldowns = buildMorningDrilldowns(result);
		const visiblePriorityItems = topPriorities.slice(0, 3);
		const queueOverview = buildMorningQueueOverview(result);
		const supportingPriorityItems = visiblePriorityItems.slice(1);
		const compactSnapshotParts = [
			(yesterdaySnapshot.orders || 0) + ' orders yesterday',
			String(yesterdaySnapshot.gross_sales || '0') + ' gross sales',
			(yesterdaySnapshot.customers || 0) + ' active customer' + ((Number(yesterdaySnapshot.customers || 0) === 1) ? '' : 's'),
			'Main pressure: ' + humanizeMorningPressure(livePressure.main_pressure || 'overview')
		];

		let html = '<div class="yoro-result-notice yoro-result-success">';
		html += '<p>' + h.escapeHtml(result.assistant_summary || result.headline || 'Here is your morning briefing.') + '</p>';

		if (result.assistant_followup) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(result.assistant_followup) + '</p>';
		}

		html += '</div>';

		html += '<div class="yoro-result-summary">';
		html += '<strong>Morning briefing</strong>';
		html += '<span class="yoro-result-subtle">Proactive morning brief. No store data is changed.</span>';
		html += '<span class="yoro-result-subtle">Proactive</span>';

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Morning briefing</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(result.time_window_label || 'Yesterday and this morning') + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(formatDateTime(result.briefing_date, result.briefing_date_ts, h)) + '</span>';
		html += '</div>';

		html += '<div class="yoro-briefing-hero">';
		html += '<div class="yoro-briefing-hero-copy">';
		html += '<strong>' + h.escapeHtml(result.headline || 'Morning briefing') + '</strong>';
		html += '<p>' + h.escapeHtml(yesterdaySnapshot.summary || '') + '</p>';
		html += '<p class="yoro-result-subtle">' + h.escapeHtml(compactSnapshotParts.join(' · ')) + '</p>';
		html += '</div>';
		html += '</div>';

		if (firstAction.command) {
			html += renderControlHomeStartHere(firstAction, result.dismissible, h);
		}
		html += renderWorklistModeCard(result, h);

		if (priorityDrilldowns.length) {
			html += renderControlHomeQueueOverview('Open one of these next', 'These drilldowns take you straight into the queues behind this morning briefing.', priorityDrilldowns, h);
		}

		if (supportingPriorityItems.length) {
			html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		}

		html += '<details class="yoro-order-detail-section yoro-briefing-disclosure">';
		html += '<summary><strong>See detail view</strong><span>Open queue counts and extra checks</span></summary>';
		html += '<div class="yoro-briefing-disclosure-body">';
		html += '<div class="yoro-briefing-metrics">';
		html += '<div><span>Yesterday orders</span><strong>' + h.escapeHtml(yesterdaySnapshot.orders || 0) + '</strong></div>';
		html += '<div><span>Gross sales</span><strong>' + h.escapeHtml(yesterdaySnapshot.gross_sales || '0') + '</strong></div>';
		html += '<div><span>Customers</span><strong>' + h.escapeHtml(yesterdaySnapshot.customers || 0) + '</strong></div>';
		html += '<div><span>Main pressure</span><strong>' + h.escapeHtml(humanizeMorningPressure(livePressure.main_pressure || 'overview')) + '</strong></div>';
		html += '<div><span>Attention</span><strong>' + h.escapeHtml(livePressure.attention_items || 0) + '</strong></div>';
		html += '<div><span>Fulfillment</span><strong>' + h.escapeHtml(livePressure.fulfillment_items || 0) + '</strong></div>';
		html += '<div><span>Customer follow-up</span><strong>' + h.escapeHtml(livePressure.customer_followup_items || 0) + '</strong></div>';
		html += '<div><span>Inventory</span><strong>' + h.escapeHtml(livePressure.inventory_items || livePressure.inventory_pressure_items || 0) + '</strong></div>';
		html += '</div>';

		if (queueOverview.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Queue overview</h4>';
			html += '<p class="yoro-result-subtle">Use this if you want the wider operating picture, not just the next drilldown.</p>';
			html += '<div class="yoro-briefing-sections-grid">';
			queueOverview.forEach(function(queue) {
				html += '<div class="yoro-briefing-section-card yoro-control-home-queue-card' + (queue.recommended ? ' is-recommended' : '') + '">';
				html += '<div class="yoro-briefing-section-head">';
				html += '<strong>' + h.escapeHtml(queue.label || 'Queue') + '</strong>';
				html += '<span>' + h.escapeHtml(queue.count || 0) + '</span>';
				html += '</div>';
				if (queue.summary) {
					html += '<p>' + h.escapeHtml(queue.summary) + '</p>';
				}
				html += '</div>';
			});
			html += '</div>';
			html += '</div>';
		}

		html += '</div>';
		html += '</details>';

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function buildMorningDrilldowns(result) {
		const items = Array.isArray(result && result.priority_drilldowns) ? result.priority_drilldowns : [];

		return items.slice(0, 3).map(function (item) {
			const count = parseInt(item && item.count ? item.count : 0, 10);

			return {
				key: item && item.topic ? item.topic : '',
				label: item && item.label ? item.label : 'Drilldown',
				count: Number.isNaN(count) ? 0 : count,
				summary: item && item.summary ? item.summary : '',
				command: item && item.command ? item.command : '',
				commandLabel: item && item.command_label ? item.command_label : (item && item.label ? item.label : 'Open queue'),
				recommended: !!(item && item.recommended)
			};
		}).filter(function (item) {
			return item.command;
		});
	}

	function humanizeMorningPressure(topic) {
		switch (String(topic || '')) {
			case 'attention':
			case 'operations_attention':
				return 'Operations attention';
			case 'fulfillment':
				return 'Fulfillment';
			case 'customer_service':
				return 'Customer follow-up';
			case 'inventory':
				return 'Inventory';
			case 'business':
				return 'Business';
			default:
				return 'Store overview';
		}
	}

	function buildMorningQueueOverview(result) {
		const livePressure = result && result.live_pressure ? result.live_pressure : {};
		const firstAction = result && result.first_recommended_action ? result.first_recommended_action : {};
		const queues = [
			{
				key: 'attention',
				label: 'Operations',
				count: parseInt(livePressure.attention_items || 0, 10),
				summary: 'Review orders that need operational attention first.',
				command: 'Show operations attention queue'
			},
			{
				key: 'fulfillment',
				label: 'Fulfillment',
				count: parseInt(livePressure.fulfillment_items || 0, 10),
				summary: 'Check shipping and processing pressure.',
				command: 'Show fulfillment queue'
			},
			{
				key: 'customer_service',
				label: 'Customer follow-up',
				count: parseInt(livePressure.customer_followup_items || 0, 10),
				summary: 'Review customer-facing follow-up work and drafts.',
				command: 'Show customer service queue'
			}
		];

		if ((livePressure.main_pressure || '') === 'inventory') {
			queues.push({
				key: 'inventory',
				label: 'Inventory',
				count: parseInt(livePressure.inventory_items || livePressure.inventory_pressure_items || 0, 10),
				summary: 'Inspect inventory pressure before it spreads into fulfillment.',
				command: 'Show inventory queue'
			});
		}

		if (firstAction.command) {
			queues.forEach(function (queue) {
				if (queue.command === firstAction.command) {
					queue.recommended = true;
				}
			});
		}

		return queues.filter(function (queue) {
			return queue.count > 0 || queue.recommended;
		});
	}

	function buildDailyQueueOverview(sections, priorityDrilldowns, briefingActions) {
		const drilldownMap = {};
		const actionMap = {};

		(priorityDrilldowns || []).forEach(function (item) {
			if (item && item.topic) {
				drilldownMap[String(item.topic)] = item;
			}
		});

		(briefingActions || []).forEach(function (item) {
			if (item && item.topic) {
				actionMap[String(item.topic)] = item;
			}
		});

		return (sections || []).map(function (section) {
			const key = String(section && section.key ? section.key : '');
			const drilldown = drilldownMap[key] || null;
			const action = actionMap[key] || null;

			return {
				key: key,
				label: section && section.label ? section.label : 'Queue',
				count: parseInt(section && section.count ? section.count : 0, 10),
				summary: section && section.summary ? section.summary : '',
				command: (drilldown && drilldown.command) || (action && action.command) || getDefaultQueueCommand(key),
				commandLabel: (drilldown && drilldown.label) || (action && action.label) || 'Open queue',
				recommended: !!drilldown
			};
		});
	}

	function getDefaultQueueCommand(key) {
		switch (String(key || '')) {
			case 'operations':
			case 'attention':
				return 'Show operations attention queue';
			case 'fulfillment':
				return 'Show fulfillment queue';
			case 'customer_service':
				return 'Show customer service queue';
			case 'inventory':
				return 'Show inventory queue';
			case 'business':
				return 'Show business summary for this week';
			default:
				return '';
		}
	}

	function renderControlHomeStartHere(firstAction, dismissible, h) {
		let html = '<div class="yoro-control-home-primary">';
		html += '<div class="yoro-control-home-primary-copy">';
		html += '<span class="yoro-control-home-kicker">Start here</span>';
		html += '<strong>' + h.escapeHtml(firstAction.label || firstAction.command || 'Open the top queue') + '</strong>';
		if (firstAction.reason) {
			html += '<p>' + h.escapeHtml(firstAction.reason) + '</p>';
		}
		html += '</div>';
		html += '<div class="yoro-suggestion-list">';
		if (firstAction.command) {
			html += renderDirectActionButton(firstAction.label || firstAction.command, firstAction.command, h, 'button button-primary');
		}
		if (dismissible) {
			html += '<button class="button button-small yoro-dismiss-morning-briefing">Dismiss for today</button>';
		}
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderControlHomeQueueOverview(title, description, queues, h) {
		if (!Array.isArray(queues) || !queues.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml(title) + '</h4>';
		if (description) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(description) + '</p>';
		}
		html += '<div class="yoro-briefing-sections-grid">';

		queues.forEach(function (queue) {
			if (!queue) {
				return;
			}

			html += '<div class="yoro-briefing-section-card yoro-control-home-queue-card' + (queue.recommended ? ' is-recommended' : '') + '">';
			html += '<div class="yoro-briefing-section-head">';
			html += '<strong>' + h.escapeHtml(queue.label || 'Queue') + '</strong>';
			html += '<span>' + h.escapeHtml(queue.count || 0) + '</span>';
			html += '</div>';
			if (queue.summary) {
				html += '<p>' + h.escapeHtml(queue.summary) + '</p>';
			}
			if (queue.command) {
				html += '<div class="yoro-suggestion-list">';
				html += renderDirectActionButton(queue.commandLabel || 'Open queue', queue.command, h);
				html += '</div>';
			}
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderDailyBriefingResult(data, h) {
		const result = data.result || {};
		const windowInfo = result.window || {};
		const briefingLabel = result.briefing_label || 'Daily briefing';
		const sections = Array.isArray(result.sections) ? result.sections : [];
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const priorityDrilldowns = Array.isArray(result.priority_drilldowns) ? result.priority_drilldowns : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const briefingContext = result.briefing_context || {};
		const topTopic = briefingContext.top_topic || 'overview';
		const state = briefingContext.state || 'steady_watch';
		const windowOrders = briefingContext.window_orders || 0;
		const grossSales = briefingContext.gross_sales || 0;
		const activeCustomers = briefingContext.active_customers || 0;
		const topPriority = priorityItems.length ? priorityItems[0] : null;
		const secondaryPriority = priorityItems.length > 1 ? priorityItems[1] : null;
		const topTopicLabel = String(topTopic || 'overview').replace(/_/g, ' ');
		const queueOverview = buildDailyQueueOverview(sections, priorityDrilldowns, []);
		const startHere = buildBriefingStartHereFromSequence(recommendedSequence);
		const supportingPriorityItems = priorityItems.slice(1, 4);

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-briefing-shell yoro-briefing-state-' + h.escapeHtml(state) + '">';
		html += '<div class="yoro-result-summary yoro-briefing-summary">';
		html += '<strong>' + h.escapeHtml(briefingLabel) + '</strong>';
		html += '<span class="yoro-result-subtle">Executive snapshot across the store\'s main operating queues.</span>';
		if (windowInfo.label) {
			html += '<span class="yoro-result-subtle">Window: ' + h.escapeHtml(windowInfo.label) + '</span>';
		}
		html += '</div>';

		html += '<div class="yoro-order-detail-card yoro-briefing-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(briefingLabel) + '</strong>';
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(formatWindowRange(windowInfo, h)) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(topTopic.replace(/_/g, ' ')) + '</span>';
		html += '</div>';

		html += '<div class="yoro-briefing-hero">';
		html += '<div class="yoro-briefing-hero-copy">';
		html += '<strong>' + h.escapeHtml((topPriority && topPriority.title) || 'Store snapshot') + '</strong>';
		if (secondaryPriority) {
			html += '<p>' + h.escapeHtml(getBriefingSecondaryLine(secondaryPriority)) + '</p>';
		} else {
			html += '<p>' + h.escapeHtml('The main operating queues are summarized below.') + '</p>';
		}
		html += '</div>';
		html += '<div class="yoro-briefing-metrics">';
		html += '<div><span>New orders</span><strong>' + h.escapeHtml(windowOrders) + '</strong></div>';
		html += '<div><span>Gross sales</span><strong>' + h.escapeHtml(grossSales) + '</strong></div>';
		html += '<div><span>Active customers</span><strong>' + h.escapeHtml(activeCustomers) + '</strong></div>';
		html += '<div><span>Main pressure</span><strong>' + h.escapeHtml(topTopicLabel) + '</strong></div>';
		html += '<div><span>Queues tracked</span><strong>' + h.escapeHtml(sections.length) + '</strong></div>';
		html += '</div>';
		html += '</div>';

		if (startHere) {
			html += renderQueueStartHereCard(startHere, h);
		}
		html += renderWorklistModeCard(result, h);

		if (queueOverview.length) {
			html += renderControlHomeQueueOverview(
				'Open one of these next',
				'Jump directly into the work surface that matches today\'s pressure.',
				queueOverview,
				h
			);
		}

		if (supportingPriorityItems.length) {
			html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		}

		html += '</div>';
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

function getBriefingSecondaryLine(priorityItem) {
		const topic = priorityItem && priorityItem.topic ? String(priorityItem.topic) : '';

		switch (topic) {
			case 'fulfillment':
				return 'Secondary pressure is building in fulfillment and still needs active review.';
			case 'customer_service':
				return 'Customer follow-up is the next pressure point worth reviewing after the main queue.';
			case 'inventory':
				return 'Inventory risk is present in the background and should stay on the watchlist.';
			case 'business':
				return 'Commercial activity is worth a quick read alongside the operating queues.';
			case 'operations_attention':
				return 'Another operational queue is also showing meaningful pressure right now.';
			default:
				if (priorityItem && priorityItem.message) {
					return String(priorityItem.message);
				}

				return 'The main operating queues are summarized below.';
	}
}

function buildBriefingStartHereFromSequence(sequence) {
	if (!Array.isArray(sequence) || !sequence.length) {
		return null;
	}

	const first = sequence[0];

	if (!first || !first.command) {
		return null;
	}

	return {
		title: first.label || first.command || 'Recommended next step',
		meta: first.kind && first.kind !== 'view' ? first.kind : '',
		message: first.reason || '',
		reason: '',
		actions: [
			{
				label: first.label || first.command,
				command: first.command
			}
		]
	};
}

function renderQueueWorkbenchIntro(config, h) {
		let html = '';
		const cardClassName = config && config.cardClassName ? String(config.cardClassName) : '';

		if (config.showAssistantIntro) {
			html += renderAssistantIntro(config.result || {}, h);
		}

		if (config.showBoundary) {
			html += renderAdminCopilotBoundaryCard(config.result || {}, h);
		}

		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(config.summary || 'Queue') + '</strong>';
		if (config.subtle) {
			html += '<span class="yoro-result-subtle">' + h.escapeHtml(config.subtle) + '</span>';
		}
		if (shouldShowDecisionRoute(h) && config.parserRule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(config.parserRule) + '</span>';
		}
		html += '</div>';

		html += '<div class="yoro-order-detail-card yoro-queue-workbench-card' + (cardClassName ? ' ' + h.escapeHtml(cardClassName) : '') + '">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(config.title || 'Queue') + '</strong>';
		if (config.contextLine) {
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(config.contextLine) + '</div>';
		}
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(config.badge || '') + '</span>';
		html += '</div>';

		return html;
	}

	function renderQueueWorkbenchHero(topPriority, metrics, h, options) {
		if (!topPriority) {
			return '';
		}

		const config = options && typeof options === 'object' ? options : {};
		const heroClassName = config.className ? String(config.className) : '';
		let html = '<div class="yoro-briefing-hero' + (heroClassName ? ' ' + h.escapeHtml(heroClassName) : '') + '">';
		html += '<div class="yoro-briefing-hero-copy">';
		html += '<strong>' + h.escapeHtml(topPriority.title || 'Queue snapshot') + '</strong>';
		html += '<p>' + h.escapeHtml(topPriority.message || '') + '</p>';
		html += '</div>';
		html += '<div class="yoro-briefing-metrics">';

		(metrics || []).forEach(function (metric) {
			if (!metric) {
				return;
			}

			html += '<div><span>' + h.escapeHtml(metric.label || '') + '</span><strong>' + h.escapeHtml(metric.value || 0) + '</strong></div>';
		});

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderQueueRecommendedSequence(items, h, title, options) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		const config = options && typeof options === 'object' ? options : {};
		const showActions = config.showActions !== false;
		const limit = typeof config.limit === 'number' ? config.limit : 4;

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml(title || 'Recommended sequence') + '</h4>';
		html += '<div class="yoro-briefing-sections-grid">';

		items.slice(0, limit).forEach(function (item, index) {
			if (!item) {
				return;
			}

			html += '<div class="yoro-briefing-section-card">';
			html += '<div class="yoro-briefing-section-head">';
			html += '<strong>' + h.escapeHtml((index + 1) + '. ' + (item.label || item.command || 'Step')) + '</strong>';
			html += '<span>' + h.escapeHtml(item.kind || 'step') + '</span>';
			html += '</div>';
			html += '<p>' + h.escapeHtml(item.reason || '') + '</p>';
			if (showActions && item.command) {
				html += '<div class="yoro-suggestion-list">';
				html += renderDirectActionButton(item.label || item.command, item.command, h);
				html += '</div>';
			}
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderQueueActionStrip(items, h, title) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let html = '<div class="yoro-suggestions yoro-briefing-suggestions">';
		html += '<strong>' + h.escapeHtml(title || 'Next actions') + '</strong>';
		html += '<div class="yoro-suggestion-list">';

		items.slice(0, 4).forEach(function (item) {
			if (!item || !item.command) {
				return;
			}

			html += renderDirectActionButton(item.label || item.command, item.command, h);
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderQueueStartHereCard(item, h, options) {
		if (!item || !Array.isArray(item.actions) || !item.actions.length) {
			return '';
		}

	const config = options && typeof options === 'object' ? options : {};
	const hiddenCommands = Array.isArray(config.hideCommands) ? config.hideCommands : [];
	const visibleActions = item.actions.filter(function (action) {
		if (!action) {
			return false;
		}
		if (action.command) {
			return hiddenCommands.indexOf(String(action.command)) === -1;
		}
		return !!action.href;
	});

		if (!visibleActions.length) {
			return '';
		}

		let html = '<div class="yoro-control-home-primary">';
		html += '<div class="yoro-control-home-primary-copy">';
		html += '<span class="yoro-control-home-kicker">Start here</span>';
		html += '<strong>' + h.escapeHtml(item.title || 'Recommended next case') + '</strong>';
		if (item.meta) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(item.meta) + '</p>';
		}
		if (item.message) {
			html += '<p>' + h.escapeHtml(item.message) + '</p>';
		}
		if (item.reason) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(item.reason) + '</p>';
		}
		html += '</div>';
	html += '<div class="yoro-suggestion-list">';
	visibleActions.slice(0, 3).forEach(function (action, index) {
		if (!action || (!action.command && !action.href)) {
			return;
		}
		if (action.href) {
			html += '<a class="button ' + (index === 0 ? 'button-primary' : 'button-small') + '" href="' + h.escapeHtml(action.href) + '" target="_blank" rel="noopener noreferrer">';
			html += h.escapeHtml(action.label || action.href);
			html += '</a>';
		} else {
			html += renderDirectActionButton(
				action.label || action.command,
				action.command,
				h,
				'button ' + (index === 0 ? 'button-primary' : 'button-small')
			);
		}
	});
		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderWorklistModeCard(result, h) {
		const worklist = buildWorklistState(result, h);

		if (!worklist) {
			return '';
		}

		let html = '<div class="yoro-control-home-primary yoro-worklist-mode-card">';
		html += '<div class="yoro-control-home-primary-copy">';
		html += '<span class="yoro-control-home-kicker">Worklist mode</span>';
		html += '<strong>' + h.escapeHtml(worklist.title) + '</strong>';
		html += '<p>' + h.escapeHtml(worklist.message) + '</p>';
		html += '<p class="yoro-result-subtle">' + h.escapeHtml(worklist.detail) + '</p>';
		html += '</div>';
		html += '<div class="yoro-suggestion-list">';
		worklist.actions.forEach(function (action, index) {
			if (!action || !action.command) {
				return;
			}
			html += renderDirectActionButton(
				action.label || action.command,
				action.command,
				h,
				'button ' + (index === 0 ? 'button-primary' : 'button-small')
			);
		});
		html += '</div>';
		html += '</div>';
		return html;
	}

	function buildWorklistState(result, h) {
		const sequence = Array.isArray(result && result.recommended_sequence) ? result.recommended_sequence : [];

		if (!sequence.length) {
			return null;
		}

		const state = typeof h.getConversationState === 'function' ? (h.getConversationState() || {}) : {};
		const followup = getRelevantFollowupState(String(result && result.result_type ? result.result_type : ''), state);
		const cursor = followup && typeof followup.recommendation_cursor === 'number'
			? followup.recommendation_cursor
			: -1;
		const normalizedCursor = cursor >= 0 ? cursor : -1;

		if (normalizedCursor < 0) {
			return null;
		}

		const refreshCommand = getWorklistRefreshCommand(result && result.result_type ? result.result_type : '');
		const actions = [
			{
				label: 'Continue plan',
				command: 'Continue the plan'
			}
		];

		if (sequence.length > 1) {
			actions.push({
				label: 'Next step',
				command: 'Do the next recommendation'
			});
		}

		if (refreshCommand) {
			actions.push({
				label: 'Refresh queue',
				command: refreshCommand
			});
		}

		return {
			title: 'Continue from where YoRo left off',
			message: 'YoRo can keep advancing the current follow-up path without making you re-open each queue manually.',
			detail: 'Step ' + (Math.max(0, normalizedCursor) + 1) + ' of ' + sequence.length + ' in the current recommendation path.',
			actions: actions
		};
	}

	function getRelevantFollowupState(resultType, state) {
		switch (String(resultType || '')) {
			case 'morning_briefing':
			case 'daily_briefing':
				return state.last_briefing || state.last_result || {};
			case 'business_summary':
				return state.last_business_followup || state.last_result || {};
			case 'operations_attention_queue':
				return state.last_attention_followup || state.last_result || {};
			case 'customer_service_queue':
				return state.last_service_followup || state.last_result || {};
			case 'fulfillment_queue':
				return state.last_fulfillment_followup || state.last_result || {};
			case 'inventory_queue':
				return state.last_inventory_followup || state.last_result || {};
			default:
				return state.last_result || {};
		}
	}

	function getWorklistRefreshCommand(resultType) {
		switch (String(resultType || '')) {
			case 'morning_briefing':
			case 'daily_briefing':
				return 'Show daily briefing for today';
			case 'business_summary':
				return 'Show business summary for this week';
			case 'operations_attention_queue':
				return 'Show operations attention queue';
			case 'customer_service_queue':
				return 'Show customer service queue';
			case 'fulfillment_queue':
				return 'Show fulfillment queue';
			case 'inventory_queue':
				return 'Show inventory queue';
			default:
				return '';
		}
	}

	function renderQueuePriorityCards(items, h, title) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml(title || 'Top priorities') + '</h4>';
		html += '<div class="yoro-insight-list">';

		items.forEach(function (item) {
			if (!item) {
				return;
			}

			html += '<div class="yoro-insight-card yoro-insight-card-' + h.escapeHtml(item.tone || 'neutral') + '">';
			html += '<strong>' + h.escapeHtml(item.title || 'Priority') + '</strong>';
			html += '<p>' + h.escapeHtml(item.message || '') + '</p>';
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderQueueMetaMetrics(metrics, h) {
		if (!Array.isArray(metrics) || !metrics.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-metrics">';

		metrics.forEach(function (metric) {
			if (!metric) {
				return;
			}

			html += '<div><span>' + h.escapeHtml(metric.label || '') + '</span><strong>' + h.escapeHtml(metric.value || 0) + '</strong></div>';
		});

		html += '</div>';
		return html;
	}

	function renderGuidedCaseDeck(config, h) {
		const cases = Array.isArray(config && config.items) ? config.items : [];

		if (!cases.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml((config && config.title) || 'Guided cases') + '</h4>';
		if (config && config.description) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(config.description) + '</p>';
		}
		html += '<div class="yoro-guided-case-grid">';

		cases.slice(0, 3).forEach(function (item) {
			if (!item) {
				return;
			}

			html += '<div class="yoro-guided-case-card">';
			html += '<div class="yoro-guided-case-head">';
			html += '<div>';
			html += '<strong>' + h.escapeHtml(item.title || 'Case') + '</strong>';
			if (item.meta) {
				html += '<div class="yoro-result-subtle">' + h.escapeHtml(item.meta) + '</div>';
			}
			html += '</div>';
			if (item.badge) {
				html += '<span class="yoro-guided-case-badge">' + h.escapeHtml(formatAgeBadge(item.badge)) + '</span>';
			}
			html += '</div>';
			if (item.message) {
				html += '<p>' + h.escapeHtml(item.message) + '</p>';
			}
			if (Array.isArray(item.actions) && item.actions.length) {
				html += '<div class="yoro-suggestion-list">';
				item.actions.slice(0, 3).forEach(function (action) {
					if (!action || (!action.command && !action.href)) {
						return;
					}
					if (action.href) {
						html += '<a class="button button-small" href="' + h.escapeHtml(action.href) + '" target="_blank" rel="noopener noreferrer">';
						html += h.escapeHtml(action.label || action.href);
						html += '</a>';
					} else {
						html += renderDirectActionButton(action.label || action.command, action.command, h);
					}
				});
				html += '</div>';
			}
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderBatchDraftPreparation(config, h) {
		const items = Array.isArray(config && config.items) ? config.items : [];

		if (!items.length) {
			return '';
		}

		return renderGuidedCaseDeck({
			title: (config && config.title) || 'Batch draft preparation',
			description: (config && config.description) || 'Review these candidate drafts next. Nothing is sent automatically.',
			items: items
		}, h);
	}

	function renderOrderQueueSections(queues, tableConfig, h) {
		let html = '';
		const config = tableConfig && typeof tableConfig === 'object' ? tableConfig : {};
		const maxRows = typeof config.maxRows === 'number' ? config.maxRows : 0;

		(queues || []).forEach(function (queue) {
			const orders = Array.isArray(queue.orders) ? queue.orders : [];
			const visibleOrders = maxRows > 0 ? orders.slice(0, maxRows) : orders;
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(queue.label || queue.key || 'Queue') + ' <span class="yoro-result-subtle">(' + h.escapeHtml(queue.count || 0) + ')</span></h4>';

			if (queue.description) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(queue.description) + '</p>';
			}

			if (!orders.length) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(tableConfig.emptyMessage || 'No matching orders in this queue.') + '</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';
			html += '<thead><tr>';
			(config.columns || []).forEach(function (column) {
				html += '<th>' + h.escapeHtml(column.label || '') + '</th>';
			});
			html += '</tr></thead><tbody>';

			visibleOrders.forEach(function (order) {
				html += config.renderRow(order, queue, h);
			});

			html += '</tbody></table>';
			if (maxRows > 0 && orders.length > visibleOrders.length) {
				html += '<p class="yoro-result-subtle">Showing the top ' + h.escapeHtml(visibleOrders.length) + ' cases in this queue.</p>';
			}
			html += '</div>';
		});

		return html;
	}

	function renderProductQueueSections(queues, h) {
		let html = '';

		(queues || []).forEach(function (queue) {
			const products = Array.isArray(queue.products) ? queue.products : [];
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(queue.label || queue.key || 'Queue') + ' <span class="yoro-result-subtle">(' + h.escapeHtml(queue.count || 0) + ')</span></h4>';

			if (queue.description) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(queue.description) + '</p>';
			}

			if (!products.length) {
				html += '<p class="yoro-result-subtle">No matching products in this queue.</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Status</th><th>Backorders</th><th>Actions</th></tr></thead><tbody>';

			products.forEach(function (product) {
				const stock = product.stock_quantity === '' || product.stock_quantity === null
					? '—'
					: product.stock_quantity;

				html += '<tr>';
				html += '<td><strong>' + h.escapeHtml(product.name || ('#' + product.id)) + '</strong></td>';
				html += '<td>' + h.escapeHtml(product.sku || '—') + '</td>';
				html += '<td>' + h.escapeHtml(stock) + ' <span class="yoro-result-subtle">/ low ' + h.escapeHtml(product.low_stock_threshold || 0) + '</span></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(product.stock_status || '') + '</span></td>';
				html += '<td>' + h.escapeHtml(product.backorders_allowed ? 'Allowed' : 'No') + '</td>';
				html += '<td class="yoro-row-actions">';
				if (product.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(product.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
				}
				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		});

		return html;
	}

	function renderInventoryProductQueueSections(queues, h, options) {
		let html = '';
		const config = options && typeof options === 'object' ? options : {};
		const maxRows = typeof config.maxRows === 'number' ? config.maxRows : 0;
		const stockManagementEnabled = !!config.stockManagementEnabled;
		const inventoryMode = String(config.inventoryMode || '');

		(queues || []).forEach(function (queue) {
			const products = Array.isArray(queue.products) ? queue.products : [];
			const visibleProducts = maxRows > 0 ? products.slice(0, maxRows) : products;
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(queue.label || queue.key || 'Queue') + ' <span class="yoro-result-subtle">(' + h.escapeHtml(queue.count || 0) + ')</span></h4>';

			if (queue.description) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(queue.description) + '</p>';
			}

			if (!products.length) {
				html += '<p class="yoro-result-subtle">No matching products in this queue.</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Product</th><th>Case</th><th>Stock</th><th>Next action</th></tr></thead><tbody>';

			visibleProducts.forEach(function (product) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const productMeta = [product.sku || '', product.stock_status || ''].filter(Boolean).join(' · ');
				const caseTitle = [
					humanizeInventoryTopic(queueKey || ''),
					product.stock_status || ''
				].filter(Boolean).join(' · ');

				html += '<tr>';
				html += '<td><strong>' + h.escapeHtml(product.name || ('#' + product.id)) + '</strong>';
				if (productMeta) {
					html += '<div class="yoro-result-subtle">' + h.escapeHtml(productMeta) + '</div>';
				}
				html += '</td>';
				html += '<td><strong>' + h.escapeHtml(caseTitle || 'Inventory case') + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(buildInventoryRowCaseMessage(queueKey, queue.description || '', product)) + '</div></td>';
				html += '<td>' + h.escapeHtml(formatInventoryRowStock(product, stockManagementEnabled, inventoryMode)) + '</td>';
				html += '<td class="yoro-row-actions">';
				if (product.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(product.edit_url) + '" target="_blank" rel="noopener noreferrer">Open product</a>';
				}
				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			if (maxRows > 0 && products.length > visibleProducts.length) {
				html += '<p class="yoro-result-subtle">Showing the top ' + h.escapeHtml(visibleProducts.length) + ' products in this queue.</p>';
			}
			html += '</div>';
		});

		return html;
	}

	function renderOperationsAttentionQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const attentionContext = result.attention_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildOperationsAttentionHeroItem(attentionContext, priorityItems, result);
		const supportingPriorityItems = filterOperationsAttentionSupportingPriorities(priorityItems, attentionContext);

		let html = renderQueueWorkbenchIntro({
			result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Operations attention queue',
				subtle: 'Orders that may need review next.',
				parserRule: parsed.parser_rule,
				title: 'Operations attention cases',
				contextLine: 'Top cases from each active queue.',
				badge: (result.count || 0) + ' items'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Attention items', value: attentionContext.total_items || result.count || 0 },
			{ label: 'Pending payment', value: attentionContext.pending_count || 0 },
			{ label: 'Aging processing', value: attentionContext.processing_count || 0 },
			{ label: 'High-value open', value: attentionContext.high_value_count || 0 }
		], h);
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show operations attention queue']
		});
			html += renderBatchDraftPreparation({
				title: 'Review these queue cases next',
				description: 'Start here when you want to clear several operations checks in one pass.',
				items: batchDraftCandidates
			}, h);
		html += renderQueueRecommendedSequence(recommendedSequence, h, 'What comes after this', {
			showActions: false,
			limit: 3
		});
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'High-value threshold', value: result.high_value_threshold || 0 }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 3,
			columns: [
				{ label: 'Order' },
				{ label: 'Case' },
				{ label: 'Age' },
				{ label: 'Next action' }
			],
			renderRow: function(order, queue, h2) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const customerMeta = order.customer_name || order.billing_email || '';
				const caseMeta = [
					humanizeAttentionTopic(queueKey || ''),
					order.status || '',
					order.total ? ((order.total || '') + ' ' + (order.currency || '')) : ''
				].filter(Boolean).join(' · ');
				const primaryCommand = getOperationsAttentionRowCommand(queueKey, order);
				const primaryLabel = getOperationsAttentionRowActionLabel(queueKey);
				const detailsCommand = 'Show order ' + order.id + ' details';
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(caseMeta || (order.status || 'Case')) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(customerMeta || queue.description || '') + '</div></td>';
				row += '<td>' + h2.escapeHtml(formatAgeHours(order.age_hours)) + '</td>';
				row += '<td class="yoro-row-actions">';
				row += renderDirectActionButton(primaryLabel, primaryCommand, h2) + ' ';
				if (primaryCommand !== detailsCommand) {
					row += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h2.escapeHtml(order.id) + '">Details</button> ';
				}
				row += '</td>';
				row += '</tr>';
				return row;
			}
		}, h);
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show operations attention queue' },
			{ label: 'Operations summary', command: 'Show operations summary for today' },
			{ label: 'Customer service', command: 'Show customer service queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderFulfillmentQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const fulfillmentContext = result.fulfillment_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildFulfillmentHeroItem(fulfillmentContext, priorityItems, result);
		const supportingPriorityItems = filterFulfillmentSupportingPriorities(priorityItems, fulfillmentContext);

		let html = renderQueueWorkbenchIntro({
			result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Fulfillment queue',
				subtle: 'Orders that may need shipping or tracking follow-up.',
				parserRule: parsed.parser_rule,
				title: 'Fulfillment candidates',
				contextLine: 'Top fulfillment cases from each queue.',
				badge: (result.count || 0) + ' fulfillment items'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Queue items', value: fulfillmentContext.total_items || result.count || 0 },
			{ label: 'Missing tracking', value: fulfillmentContext.tracking_count || 0 },
			{ label: 'Aging orders', value: fulfillmentContext.aging_count || 0 },
			{ label: 'Tracked ready', value: fulfillmentContext.ready_count || 0 }
		], h);
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show fulfillment queue']
		});
			html += renderBatchDraftPreparation({
				title: 'Review these fulfillment cases next',
				description: 'Start here when you want to move through several shipping follow-ups.',
				items: batchDraftCandidates
			}, h);
		html += renderQueueRecommendedSequence(recommendedSequence, h, 'What comes after this', {
			showActions: false,
			limit: 3
		});
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'Store changes', value: 'No' }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 3,
			columns: [
				{ label: 'Order' },
				{ label: 'Case' },
				{ label: 'Age' },
				{ label: 'Next action' }
			],
			renderRow: function(order, queue, h2) {
				const shipTo = [order.shipping_city || '', order.shipping_country || ''].filter(Boolean).join(', ');
				const queueKey = String(queue && queue.key ? queue.key : '');
				const primaryCommand = order.suggested_command || ('Preview shipping delay email for order ' + order.id);
				const primaryLabel = getFulfillmentRowActionLabel(queueKey);
				const caseMeta = [
					humanizeFulfillmentTopic(queueKey || ''),
					order.shipping_method || '',
					shipTo || ''
				].filter(Boolean).join(' · ');
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(caseMeta || (order.status || 'Case')) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(order.fulfillment_reason || queue.description || '') + '</div></td>';
				row += '<td>' + h2.escapeHtml(formatAgeHours(order.age_hours)) + '</td>';
				row += '<td class="yoro-row-actions">';
				row += renderDirectActionButton(primaryLabel, primaryCommand, h2) + ' ';
				row += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h2.escapeHtml(order.id) + '">Details</button> ';
				row += '</td>';
				row += '</tr>';
				return row;
			}
		}, h);
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show fulfillment queue' },
			{ label: 'Processing orders', command: 'Show processing orders' },
			{ label: 'Customer service', command: 'Show customer service queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderInventoryQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const inventoryContext = result.inventory_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildInventoryHeroItem(inventoryContext, priorityItems, result);
		const supportingPriorityItems = filterInventorySupportingPriorities(priorityItems, inventoryContext);
		const stockManagementEnabled = !!inventoryContext.stock_management_enabled;
			const inventoryMode = String(inventoryContext.inventory_mode || result.inventory_mode || '');
			const inventorySubtle = stockManagementEnabled
				? 'Products that may need stock attention.'
				: 'Stock status view. Quantity tracking is not enabled for these products.';
		const heroMetrics = [
			{ label: 'Queue items', value: inventoryContext.total_items || result.count || 0 },
			{ label: 'Out of stock', value: inventoryContext.out_of_stock_count || 0 },
			{ label: 'Backorders', value: inventoryContext.backorder_count || 0 },
			{ label: stockManagementEnabled ? 'Low stock' : 'Mode', value: stockManagementEnabled ? (inventoryContext.low_stock_count || 0) : 'Status only' }
		];

		let html = renderQueueWorkbenchIntro({
			result: result,
			showAssistantIntro: true,
			showBoundary: false,
			summary: result.summary || 'Inventory queue',
			subtle: inventorySubtle,
				parserRule: parsed.parser_rule,
				title: 'Inventory attention',
				contextLine: inventoryMode === 'status_only'
					? 'Top stock-status exceptions.'
					: 'Top products from each inventory queue.',
				badge: (result.count || 0) + ' items'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, heroMetrics, h);
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show inventory queue']
		});
			html += renderBatchDraftPreparation({
				title: stockManagementEnabled ? 'Review these inventory items next' : 'Review these stock-status items next',
				description: stockManagementEnabled
					? 'Start here when you want to move through several inventory risks.'
					: 'Start here with the clearest stock-status exceptions.',
				items: batchDraftCandidates
			}, h);
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'Stock tracking', value: stockManagementEnabled ? 'Enabled' : 'Off' }
		], h);
		html += renderInventoryProductQueueSections(queues, h, {
			maxRows: 3,
			stockManagementEnabled: stockManagementEnabled,
			inventoryMode: inventoryMode
		});
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show inventory queue' },
			{ label: stockManagementEnabled ? 'Fulfillment' : 'Store summary', command: stockManagementEnabled ? 'Show fulfillment queue' : 'Show business summary for this week' },
			{ label: 'Operations', command: 'Show operations attention queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderStoreInsightsResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const windowInfo = result.window || {};
		const insights = Array.isArray(result.insights) ? result.insights : [];
		const ordersSection = insights.find(function(section) { return section && section.key === 'orders'; }) || {};
		const customersSection = insights.find(function(section) { return section && section.key === 'customers'; }) || {};
		const productsSection = insights.find(function(section) { return section && section.key === 'products'; }) || {};
		const productMetrics = productsSection.metrics || {};
		const inventoryRiskCount = (parseInt(productMetrics.out_of_stock || 0, 10) || 0)
			+ (parseInt(productMetrics.low_stock || 0, 10) || 0)
			+ (parseInt(productMetrics.on_backorder || 0, 10) || 0);
		const topMetrics = [
			{
				label: 'Orders',
				value: ((ordersSection.metrics || {}).order_count || 0) + '',
				subtle: windowInfo.label || ''
			},
			{
				label: 'Gross sales',
				value: ((ordersSection.metrics || {}).gross_sales || '0') + ' ' + ((result.meta || {}).currency || ''),
				subtle: 'Across this window'
			},
			{
				label: 'Customers',
				value: ((customersSection.metrics || {}).unique_customers || 0) + '',
				subtle: 'Unique buyers'
			},
			{
				label: 'Inventory risk',
				value: inventoryRiskCount + '',
				subtle: 'Out of stock, low stock, and backorders'
			}
		];

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Grouped store snapshot') + '</strong>';
		html += '<span class="yoro-result-subtle">Use this grouped snapshot when you want a deeper read across orders, customers, and products.</span>';

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		if (windowInfo.label) {
			html += '<span class="yoro-result-subtle">Window: ' + h.escapeHtml(windowInfo.label) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Grouped store snapshot</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(formatWindowRange(windowInfo, h)) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(windowInfo.label || (insights.length + ' groups')) + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics yoro-order-detail-metrics-compact">';
		topMetrics.forEach(function(metric) {
			html += '<div>';
			html += '<span>' + h.escapeHtml(metric.label || '') + '</span>';
			html += '<strong>' + h.escapeHtml(metric.value || '') + '</strong>';
			if (metric.subtle) {
				html += '<small class="yoro-result-subtle">' + h.escapeHtml(metric.subtle) + '</small>';
			}
			html += '</div>';
		});
		html += '</div>';

		insights.forEach(function(section) {
			const metrics = section.metrics || {};
			const items = Array.isArray(section.items) ? section.items : [];
			const sectionMeta = getStoreInsightsSectionMeta(section.key);

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(sectionMeta.title || section.label || section.key || 'Insights') + '</h4>';
			if (sectionMeta.summary) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(sectionMeta.summary) + '</p>';
			}

			html += '<div class="yoro-order-detail-metrics">';
			Object.keys(metrics).forEach(function(key) {
				html += '<div><span>' + h.escapeHtml(humanizeStoreInsightsMetricLabel(key)) + '</span><strong>' + h.escapeHtml(metrics[key]) + '</strong></div>';
			});
			html += '</div>';

			if (!items.length) {
				html += '<p class="yoro-result-subtle">Nothing notable in this group for the selected window.</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';

			if (section.key === 'customers') {
				html += '<thead><tr><th>Customer</th><th>Email</th><th>Orders</th><th>Total spent</th></tr></thead><tbody>';
				items.forEach(function(item) {
					html += '<tr>';
					html += '<td>' + h.escapeHtml(item.name || '—') + '</td>';
					html += '<td>' + h.escapeHtml(item.email || '') + '</td>';
					html += '<td>' + h.escapeHtml(item.order_count || 0) + '</td>';
					html += '<td>' + h.escapeHtml(item.total_spent || 0) + '</td>';
					html += '</tr>';
				});
			} else if (section.key === 'products') {
				html += '<thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
				items.forEach(function(item) {
					html += '<tr>';
					html += '<td>' + h.escapeHtml(item.name || ('#' + item.id)) + '</td>';
					html += '<td>' + h.escapeHtml(item.sku || '—') + '</td>';
					html += '<td>' + h.escapeHtml(item.quantity || 0) + '</td>';
					html += '<td>' + h.escapeHtml(item.total || 0) + '</td>';
					html += '</tr>';
				});
			} else {
				html += '<thead><tr><th>Item</th><th>Count</th></tr></thead><tbody>';
				items.forEach(function(item) {
					html += '<tr>';
					html += '<td>' + h.escapeHtml(item.label || item.key || '') + '</td>';
					html += '<td>' + h.escapeHtml(item.count || 0) + '</td>';
					html += '</tr>';
				});
			}

			html += '</tbody></table>';
			html += '</div>';
		});

		html += renderQueueActionStrip([
			{ label: 'Business summary', command: 'Show business summary for this week' },
			{ label: 'Operations', command: 'Show operations summary for today' },
			{ label: 'Inventory', command: 'Show inventory queue' }
		], h, 'Other views');

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function getStoreInsightsSectionMeta(key) {
		switch (String(key || '')) {
			case 'orders':
				return {
					title: 'Order mix',
					summary: 'Use this group to see how order volume and status mix are behaving in the selected window.'
				};
			case 'customers':
				return {
					title: 'Top customers',
					summary: 'This group highlights who spent the most and how concentrated customer value looks right now.'
				};
			case 'products':
				return {
					title: 'Product demand and inventory pressure',
					summary: 'This group combines top-selling items with the current stock signals that may affect fulfillment.'
				};
			default:
				return {
					title: 'Insights group',
					summary: ''
				};
		}
	}

	function humanizeStoreInsightsMetricLabel(key) {
		switch (String(key || '')) {
			case 'order_count':
				return 'Orders';
			case 'gross_sales':
				return 'Gross sales';
			case 'average_order_value':
				return 'Average order value';
			case 'unique_customers':
				return 'Unique customers';
			case 'out_of_stock':
				return 'Out of stock';
			case 'low_stock':
				return 'Low stock';
			case 'on_backorder':
				return 'On backorder';
			default:
				return String(key || '').replace(/_/g, ' ');
		}
	}

	function renderCustomerServiceQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const serviceContext = result.service_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildCustomerServiceHeroItem(serviceContext, priorityItems, result);
		const supportingPriorityItems = filterCustomerServiceSupportingPriorities(priorityItems, serviceContext);

		let html = renderQueueWorkbenchIntro({
			result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Customer service queue',
				subtle: 'Customer follow-ups that may need a reply.',
				parserRule: parsed.parser_rule,
				title: 'Customer follow-up candidates',
				contextLine: 'Top customer cases from each queue.',
				badge: (result.count || 0) + ' follow-ups',
				cardClassName: 'yoro-queue-workbench-card-customer-service'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Follow-up items', value: serviceContext.total_items || result.count || 0 },
			{ label: 'On-hold', value: serviceContext.on_hold_count || 0 },
			{ label: 'Pending payment', value: serviceContext.payment_count || 0 },
			{ label: 'Saved drafts', value: serviceContext.draft_count || 0 }
		], h, {
			className: 'yoro-briefing-hero-compact'
		});
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show customer service queue']
		});
			html += renderBatchDraftPreparation({
				title: 'Prepare these reply drafts next',
				description: 'Start here when you want to finish several likely replies.',
				items: batchDraftCandidates
			}, h);
		html += renderQueueRecommendedSequence(recommendedSequence, h, 'What comes after this', {
			showActions: false,
			limit: 3
		});
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'Auto-send', value: 'No' }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 3,
			columns: [
				{ label: 'Order' },
				{ label: 'Case' },
				{ label: 'Age' },
				{ label: 'Next action' }
			],
			renderRow: function(order, queue, h2) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const rowActionLabel = getCustomerServiceRowActionLabel(queueKey);
				const rowActionCommand = getCustomerServiceRowActionCommand(queueKey, order);
				const caseMeta = [
					order.customer_name || order.billing_email || 'Customer',
					humanizeServiceTopic(queueKey || ''),
					order.status || ''
				].filter(Boolean).join(' · ');
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(caseMeta) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(order.followup_reason || '') + '</div></td>';
				row += '<td>' + h2.escapeHtml(formatAgeHours(order.age_hours)) + '</td>';
				row += '<td class="yoro-row-actions">';
				row += renderDirectActionButton(rowActionLabel, rowActionCommand, h2) + ' ';
				row += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h2.escapeHtml(order.id) + '">Details</button> ';
				row += '</td>';
				row += '</tr>';
				return row;
			}
		}, h);
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show customer service queue' },
			{ label: 'Operations', command: 'Show operations attention queue' },
			{ label: 'Pending orders', command: 'Show pending orders' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function humanizeServiceTopic(topic) {
		switch (String(topic || '')) {
			case 'on_hold_followup':
				return 'On-hold follow-up';
			case 'pending_payment_followup':
				return 'Pending payment';
			case 'saved_email_drafts':
				return 'Saved drafts';
			case 'failed_checkout_followup':
				return 'Failed checkout';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function buildCustomerServiceHeroItem(serviceContext, priorityItems, result) {
		const topTopic = String(serviceContext && serviceContext.top_topic ? serviceContext.top_topic : '');
		const totalItems = serviceContext && serviceContext.total_items ? serviceContext.total_items : (result && result.count ? result.count : 0);
		const onHold = serviceContext && serviceContext.on_hold_count ? serviceContext.on_hold_count : 0;
		const drafts = serviceContext && serviceContext.draft_count ? serviceContext.draft_count : 0;
		const payments = serviceContext && serviceContext.payment_count ? serviceContext.payment_count : 0;
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		let title = 'Customer replies need attention across the queue.';
		let message = 'Use Start here for the fastest next case, then work through the shortlist below.';

		if (topTopic === 'on_hold_followup') {
			title = 'Most customer reply pressure is sitting in on-hold orders.';
			message = 'There are ' + onHold + ' on-hold follow-ups carrying most of the live backlog, plus ' + drafts + ' saved drafts you can finish quickly.';
		} else if (topTopic === 'saved_email_drafts') {
			title = 'Saved drafts give you the fastest route to completed replies.';
			message = 'There are ' + drafts + ' saved drafts ready now, while the wider queue still holds ' + totalItems + ' customer follow-up items.';
		} else if (topTopic === 'pending_payment_followup') {
			title = 'Payment follow-ups are the clearest active customer pressure.';
			message = 'There are ' + payments + ' pending payment cases to clear, plus ' + drafts + ' saved drafts you can finish quickly.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterCustomerServiceSupportingPriorities(priorityItems, serviceContext) {
		const topTopic = String(serviceContext && serviceContext.top_topic ? serviceContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function getCustomerServiceRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'saved_email_drafts':
				return 'Open draft';
			case 'pending_payment_followup':
				return 'Draft payment follow-up';
			case 'on_hold_followup':
				return 'Draft on-hold reply';
			case 'failed_checkout_followup':
				return 'Draft support reply';
			default:
				return 'Draft reply';
		}
	}

	function getCustomerServiceRowActionCommand(queueKey, order) {
		const orderId = order && order.id ? order.id : '';

		if (String(queueKey || '') === 'saved_email_drafts') {
			return 'Load email drafts for order #' + orderId;
		}

		return (order && order.suggested_command) || ('Write email for order #' + orderId);
	}

	function humanizeAttentionTopic(topic) {
		switch (String(topic || '')) {
			case 'pending_payment_older_than_24h':
				return 'Pending payment';
			case 'processing_older_than_72h':
				return 'Aging processing';
			case 'on_hold_older_than_48h':
				return 'On-hold';
			case 'recent_failed_orders':
				return 'Failed orders';
			case 'high_value_open_orders':
				return 'High-value open';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function getOperationsAttentionRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'pending_payment_older_than_24h':
				return 'Draft payment follow-up';
			case 'processing_older_than_72h':
				return 'Preview delay email';
			case 'on_hold_older_than_48h':
				return 'Draft on-hold reply';
			case 'recent_failed_orders':
				return 'Draft support reply';
			case 'high_value_open_orders':
				return 'Open order details';
			default:
				return 'Review order';
		}
	}

	function getOperationsAttentionRowCommand(queueKey, order) {
		const orderId = parseInt(order && order.id ? order.id : 0, 10);
		if (!orderId) {
			return '';
		}

		switch (String(queueKey || '')) {
			case 'pending_payment_older_than_24h':
				return 'Write email for order #' + orderId + ' about: Payment follow-up may be useful.';
			case 'processing_older_than_72h':
				return 'Preview shipping delay email for order ' + orderId;
			case 'on_hold_older_than_48h':
				return 'Write email for order #' + orderId + ' about: Order is still on hold and may need clarification.';
			case 'recent_failed_orders':
				return 'Write email for order #' + orderId + ' about: Failed checkout may need support follow-up.';
			case 'high_value_open_orders':
				return 'Show order ' + orderId + ' details';
			default:
				return 'Show order ' + orderId + ' details';
		}
	}

	function buildOperationsAttentionHeroItem(attentionContext, priorityItems, result) {
		const topTopic = String(attentionContext && attentionContext.top_topic ? attentionContext.top_topic : '');
		const pending = attentionContext && attentionContext.pending_count ? attentionContext.pending_count : 0;
		const processing = attentionContext && attentionContext.processing_count ? attentionContext.processing_count : 0;
		const onHold = attentionContext && attentionContext.on_hold_count ? attentionContext.on_hold_count : 0;
		const highValue = attentionContext && attentionContext.high_value_count ? attentionContext.high_value_count : 0;
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		let title = 'Operations attention still needs a direct pass.';
		let message = 'Use Start here for the clearest next case, then move through the shortlist below.';

		if (topTopic === 'pending_payment_older_than_24h') {
			title = 'Pending payment is carrying most of the operations pressure.';
			message = 'There are ' + pending + ' payment-delay cases, while ' + processing + ' processing orders are still aging behind them.';
		} else if (topTopic === 'processing_older_than_72h') {
			title = 'Aging processing orders now need the next manual check.';
			message = 'There are ' + processing + ' older processing orders to review, plus ' + pending + ' payment-delay cases still waiting.';
		} else if (topTopic === 'on_hold_older_than_48h') {
			title = 'On-hold orders are waiting for a clear decision.';
			message = 'There are ' + onHold + ' older on-hold orders still sitting in backlog, with ' + pending + ' payment-delay cases also active.';
		} else if (topTopic === 'high_value_open_orders') {
			title = 'High-value open orders deserve the next direct check.';
			message = 'There are ' + highValue + ' higher-value open orders still unresolved, alongside ' + processing + ' aging processing cases.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterOperationsAttentionSupportingPriorities(priorityItems, attentionContext) {
		const topTopic = String(attentionContext && attentionContext.top_topic ? attentionContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function humanizeBusinessTopic(topic) {
		switch (String(topic || '')) {
			case 'state_summary':
				return 'Commercial health';
			case 'operations_pressure':
				return 'Operational pressure';
			case 'sales':
				return 'Sales';
			case 'fulfillment_velocity':
				return 'Fulfillment velocity';
			case 'open_order_mix':
				return 'Open order mix';
			case 'top_customers':
				return 'Customer mix';
			case 'top_products':
				return 'Product demand';
			case 'sales_gap':
				return 'Sales gap';
			default:
				return String(topic || 'overview').replace(/_/g, ' ');
		}
	}

	function humanizeFulfillmentTopic(topic) {
		switch (String(topic || '')) {
			case 'processing_without_tracking':
				return 'Missing tracking';
			case 'processing_older_than_48h':
				return 'Aging shippable order';
			case 'missing_shipping_address':
				return 'Address gap';
			case 'recent_ready_to_fulfill':
				return 'Tracked ready order';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function getFulfillmentRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'processing_without_tracking':
				return 'Preview delay email';
			case 'missing_shipping_address':
				return 'Check address issue';
			case 'processing_older_than_48h':
				return 'Review aging order';
			case 'recent_ready_to_fulfill':
				return 'Preview fulfillment check';
			default:
				return 'Preview follow-up';
		}
	}

	function buildFulfillmentHeroItem(fulfillmentContext, priorityItems, result) {
		const topTopic = String(fulfillmentContext && fulfillmentContext.top_topic ? fulfillmentContext.top_topic : '');
		const tracking = fulfillmentContext && fulfillmentContext.tracking_count ? fulfillmentContext.tracking_count : 0;
		const aging = fulfillmentContext && fulfillmentContext.aging_count ? fulfillmentContext.aging_count : 0;
		const address = fulfillmentContext && fulfillmentContext.address_count ? fulfillmentContext.address_count : 0;
		const ready = fulfillmentContext && fulfillmentContext.ready_count ? fulfillmentContext.ready_count : 0;
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		let title = 'Shippable fulfillment cases still need active review.';
		let message = 'Use Start here for the clearest next shipping case, then work through the shortlist below.';

		if (topTopic === 'processing_without_tracking') {
			title = 'Missing tracking is carrying most of the shipping risk.';
			message = 'There are ' + tracking + ' shippable processing orders without tracking signals, while ' + aging + ' more are aging deeper into the queue.';
		} else if (topTopic === 'missing_shipping_address') {
			title = 'Address gaps are blocking otherwise normal shipment work.';
			message = 'There are ' + address + ' shippable orders with incomplete shipping details, while ' + ready + ' tracked orders are otherwise ready to move.';
		} else if (topTopic === 'processing_older_than_48h') {
			title = 'Older shippable processing orders now need the next manual check.';
			message = 'There are ' + aging + ' older shippable orders to review, plus ' + tracking + ' missing-tracking cases that may need outbound follow-up.';
		} else if (topTopic === 'recent_ready_to_fulfill') {
			title = 'Tracked orders are ready for the next fulfillment pass.';
			message = 'There are ' + ready + ' recent tracked orders with complete shipping details ready to move through the next step.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterFulfillmentSupportingPriorities(priorityItems, fulfillmentContext) {
		const topTopic = String(fulfillmentContext && fulfillmentContext.top_topic ? fulfillmentContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function humanizeInventoryTopic(topic) {
		switch (String(topic || '')) {
			case 'out_of_stock':
				return 'Stockouts';
			case 'low_stock':
				return 'Low stock';
			case 'on_backorder':
				return 'Backorders';
			case 'unmanaged_stock_selling':
				return 'Unmanaged stock';
			case 'low_stock_in_processing_orders':
				return 'Processing demand';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function buildInventoryHeroItem(inventoryContext, priorityItems) {
		const topTopic = String(inventoryContext && inventoryContext.top_topic ? inventoryContext.top_topic : '');
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		const stockouts = inventoryContext && inventoryContext.out_of_stock_count ? inventoryContext.out_of_stock_count : 0;
		const backorders = inventoryContext && inventoryContext.backorder_count ? inventoryContext.backorder_count : 0;
		const lowStock = inventoryContext && inventoryContext.low_stock_count ? inventoryContext.low_stock_count : 0;
		const processingDemand = inventoryContext && inventoryContext.recent_processing_count ? inventoryContext.recent_processing_count : 0;
		const statusOnly = String(inventoryContext && inventoryContext.inventory_mode ? inventoryContext.inventory_mode : '') === 'status_only';
		let title = statusOnly
			? 'Stock-status exceptions are the clearest inventory signal right now.'
			: 'Inventory pressure is concentrating in a few products.';
		let message = statusOnly
			? 'Use Start here for the cleanest stock-status exception, then work through the shortlist below.'
			: 'Use Start here for the cleanest stock-risk case, then move through the shortlist below.';

		if (topTopic === 'out_of_stock') {
			title = statusOnly
				? 'Out-of-stock products are the clearest stock-status exception right now.'
				: 'Stockouts are the clearest inventory risk right now.';
			message = 'There are ' + stockouts + ' out-of-stock products to review' + (backorders ? ', with ' + backorders + ' more already on backorder.' : '.');
		} else if (topTopic === 'on_backorder') {
			title = 'Backorders are still building behind active demand.';
			message = 'There are ' + backorders + ' backordered products, and ' + stockouts + ' more are already out of stock.';
		} else if (topTopic === 'low_stock_in_processing_orders') {
			title = 'Recent order demand is now consuming the remaining stock.';
			message = 'There are ' + processingDemand + ' products already under live processing demand, alongside ' + lowStock + ' other low-stock items.';
		} else if (topTopic === 'low_stock') {
			title = 'Low-stock items should be queued for replenishment now.';
			message = 'There are ' + lowStock + ' low-stock products to review before they turn into stockouts.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterInventorySupportingPriorities(priorityItems, inventoryContext) {
		const topTopic = String(inventoryContext && inventoryContext.top_topic ? inventoryContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function formatInventoryRowStock(product, stockManagementEnabled, inventoryMode) {
		const quantity = product && Object.prototype.hasOwnProperty.call(product, 'stock_quantity') ? product.stock_quantity : '';
		const threshold = product && product.low_stock_threshold ? product.low_stock_threshold : 0;
		const stockStatus = String(product && product.stock_status ? product.stock_status : '');

		if (quantity === '' || quantity === null) {
			return inventoryMode === 'status_only'
				? stockStatus.replace(/_/g, ' ')
				: 'Quantity not tracked';
		}

		let text = String(quantity);
		if (stockManagementEnabled && threshold) {
			text += ' / low ' + String(threshold);
		}
		return text;
	}

	function buildInventoryRowCaseMessage(queueKey, fallbackDescription, product) {
		const openOrderCount = parseInt(product && product.open_order_count ? product.open_order_count : 0, 10) || 0;

		switch (String(queueKey || '')) {
			case 'out_of_stock':
				return 'This product is already out of stock and should be reviewed first.';
			case 'on_backorder':
				return 'This product is already on backorder and may now be feeding directly into customer or fulfillment pressure.';
			case 'low_stock_in_processing_orders':
				return openOrderCount > 0
					? 'Recent processing orders already depend on this item (' + openOrderCount + ').'
					: 'Recent processing demand is already pulling on the remaining stock for this item.';
			case 'low_stock':
				return 'Stock is already at or below the low-stock threshold.';
			case 'unmanaged_stock_selling':
				return 'This product is selling without quantity tracking.';
			default:
				return fallbackDescription || 'Review this product inside the current inventory queue.';
		}
	}

	function renderRefundPreviewResult(data, h) {
		const result = data.result || {};
		const order = result.order || {};

		const totals = result.totals || {};
		const items = Array.isArray(result.items) ? result.items : [];
		const refunds = Array.isArray(result.refunds) ? result.refunds : [];

		let html = '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Refund preview') + '</strong>';
		html += '<span class="yoro-result-subtle">Read-only preview</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-card">';

		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Order #' + h.escapeHtml(order.number || order.id || '') + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(formatDateTime(order.date_created, order.date_created_ts, h)) + '</div>';
		html += '</div>';
		html += '<span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Order total</span><strong>' + h.escapeHtml(order.total || '0') + ' ' + h.escapeHtml(order.currency || '') + '</strong></div>';
		html += '<div><span>Refunded</span><strong>' + h.escapeHtml(order.total_refunded || '0') + ' ' + h.escapeHtml(order.currency || '') + '</strong></div>';
		html += '<div><span>Remaining</span><strong>' + h.escapeHtml(order.remaining || '0') + ' ' + h.escapeHtml(order.currency || '') + '</strong></div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Payment</h4>';
		html += '<p><strong>Method:</strong> ' + h.escapeHtml(order.payment_title || order.payment_method || '—') + '</p>';
		html += '<p><strong>Transaction ID:</strong> ' + h.escapeHtml(order.transaction_id || '—') + '</p>';
		html += '<p><strong>Can refund:</strong> ' + h.escapeHtml(result.can_refund ? 'Yes' : 'No') + '</p>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Totals</h4>';
		html += '<table class="yoro-orders-table">';
		html += '<tbody>';
		Object.keys(totals).forEach(function(key) {
			html += '<tr>';
			html += '<th>' + h.escapeHtml(key.replace(/_/g, ' ')) + '</th>';
			html += '<td>' + h.escapeHtml(totals[key]) + ' ' + h.escapeHtml(order.currency || '') + '</td>';
			html += '</tr>';
		});
		html += '</tbody>';
		html += '</table>';
		html += '</div>';

		if (items.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Refundable line items</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Refunded Qty</th><th>Remaining Qty</th><th>Line Total</th><th>Remaining</th></tr></thead>';
			html += '<tbody>';

			items.forEach(function(item) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(item.product_name || '') + '</td>';
				html += '<td>' + h.escapeHtml(item.sku || '') + '</td>';
				html += '<td>' + h.escapeHtml(item.quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(item.refunded_quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(item.remaining_quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(item.line_total || '0') + '</td>';
				html += '<td>' + h.escapeHtml(item.remaining_total || '0') + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		}

		if (refunds.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Existing refunds</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Refund</th><th>Amount</th><th>Reason</th><th>Date</th></tr></thead>';
			html += '<tbody>';

			refunds.forEach(function(refund) {
				html += '<tr>';
				html += '<td>#' + h.escapeHtml(refund.id || '') + '</td>';
				html += '<td>' + h.escapeHtml(refund.amount || '0') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(refund.reason || '—') + '</td>';
				html += '<td>' + h.escapeHtml(formatDateTime(refund.date, refund.date_ts, h)) + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		}

		html += '<div class="yoro-order-detail-actions">';
		html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '" data-action-scope="detail" data-has-email-drafts="' + (order.has_email_drafts ? '1' : '0') + '" data-draft-count="' + h.escapeHtml(order.draft_count || 0) + '">Actions</button>';
		html += '<button type="button" class="button button-small yoro-row-run-hold" data-order-id="' + h.escapeHtml(order.id) + '">Hold</button>';
		html += '<button type="button" class="button button-small yoro-row-update-status" data-order-id="' + h.escapeHtml(order.id) + '">Change status</button>';
		html += '<button type="button" class="button button-small yoro-row-add-note" data-order-id="' + h.escapeHtml(order.id) + '">Add note</button>';
		html += '<button type="button" class="button button-small yoro-row-write-email" data-order-id="' + h.escapeHtml(order.id) + '">Write email</button>';
		if (order.edit_url) {
			html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open order</a>';
		}
		html += '</div>';

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}	
		
	function handleOrderClick(event, h) {
		const rawTarget = event.target;

		const target =
			rawTarget.closest(
				'.yoro-select-all,' +
				'.yoro-run-hold,' +
				'.yoro-row-preview-hold,' +
				'.yoro-row-run-hold,' +
				'.yoro-row-details,' +
				'.yoro-row-add-note,' +
				'.yoro-row-write-email,' +
				'.yoro-row-update-status,' +
				'.yoro-bulk-details,' +
				'.yoro-bulk-add-note,' +
				'.yoro-bulk-update-status,' +
				'.yoro-bulk-status-note,' +
				'.yoro-row-action-menu,' +
				'.yoro-modal-action'
			);

		if (!target) {
			return false;
		}

		if (target.classList.contains('yoro-select-all')) {
			const checked = target.checked;
			const table = target.closest('table');

			if (table) {
				table.querySelectorAll('.yoro-order-checkbox').forEach(function (cb) {
					cb.checked = checked;
				});
			}

			return true;
		}

		if (target.classList.contains('yoro-run-hold')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				alertMissingOrderSelection();
				return true;
			}

			executeOrderUiAction(h, 'orders.hold', {
				order_ids: ids
			}, false);
			return true;
		}

		if (target.classList.contains('yoro-row-preview-hold')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				executeOrderUiAction(h, 'orders.hold', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)]
				}, true);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-run-hold')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				executeOrderUiAction(h, 'orders.hold', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)]
				}, false);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-details')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				h.setCurrentOrderId(orderId);
				executeOrderUiAction(h, 'orders.get_details', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)]
				}, false);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-add-note')) {
			const orderId = target.getAttribute('data-order-id');

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			h.openNoteModal(function(note) {
				executeOrderUiAction(h, 'orders.add_note', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)],
					note: note
				}, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-row-write-email')) {
			const orderId = target.getAttribute('data-order-id');

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			h.openTextModal('Write customer email', 'What should the email say?', function(instruction) {
				executeOrderUiAction(h, 'orders.compose_customer_email', {
					order_id: parseInt(orderId, 10),
					instruction: instruction
				}, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-row-update-status')) {
			const orderId = target.getAttribute('data-order-id');

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			h.openStatusModal(function(status) {
				executeOrderUiAction(h, 'orders.update_status', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)],
					status: status
				}, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-bulk-details')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				alertMissingOrderSelection();
				return true;
			}

			executeOrderUiAction(h, 'orders.get_details', {
				order_ids: ids
			}, false);
			return true;
		}

		if (target.classList.contains('yoro-bulk-add-note')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				alertMissingOrderSelection();
				return true;
			}

			h.openNoteModal(function(note) {
				executeOrderUiAction(h, 'orders.add_note', {
					order_ids: ids,
					note: note
				}, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-bulk-update-status')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				alertMissingOrderSelection();
				return true;
			}

			h.openStatusModal(function(status) {
				executeOrderUiAction(h, 'orders.update_status', {
					order_ids: ids,
					status: status
				}, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-bulk-status-note')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				alertMissingOrderSelection();
				return true;
			}

			h.openStatusNoteModal(function(status, note) {
				executeOrderUiAction(h, 'orders.bulk_update_status_with_note', {
					order_ids: ids,
					status: status,
					note: note
				}, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-row-action-menu')) {
			const orderId = target.getAttribute('data-order-id');
			const actionScope = target.getAttribute('data-action-scope') || 'row';
			const draftCount = parseInt(target.getAttribute('data-draft-count') || '0', 10) || 0;
			const hasEmailDrafts = target.getAttribute('data-has-email-drafts') === '1' || draftCount > 0;

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			const loadEmailDraftsButton = hasEmailDrafts
				? '<button type="button" class="button yoro-modal-action" data-action="load_email_drafts">Load email drafts</button>'
				: '';

			const actionButtons = actionScope === 'detail'
				? `
				<div class="yoro-modal-action-list">
					${loadEmailDraftsButton}
					<button type="button" class="button yoro-modal-action" data-action="identity">Find by identity</button>
					<button type="button" class="button yoro-modal-action" data-action="refund_preview">Refund preview</button>
				</div>
				`
				: `
				<div class="yoro-modal-action-list">
					<button type="button" class="button yoro-modal-action" data-action="hold">Hold</button>
					<button type="button" class="button yoro-modal-action" data-action="status">Change status</button>
					<button type="button" class="button yoro-modal-action" data-action="note">Add note</button>
					<button type="button" class="button yoro-modal-action" data-action="write_email">Write email</button>
					${loadEmailDraftsButton}
					<button type="button" class="button yoro-modal-action" data-action="identity">Find by identity</button>
					<button type="button" class="button yoro-modal-action" data-action="refund_preview">Refund preview</button>
				</div>
				`;

			h.openModal(
				'Order #' + orderId + ' actions',
				actionButtons,
				null,
				{
					hideActions: true
				}
			);

			document.querySelectorAll('.yoro-modal-action').forEach(function(btn) {
				btn.onclick = function() {
					const action = btn.getAttribute('data-action');

					h.setCurrentOrderId(orderId);

					const modal = btn.closest('.yoro-modal');
					if (modal) {
						modal.classList.add('hidden');
					}

					if (action === 'identity') {
						executeOrderUiAction(h, 'orders.find_by_identity', {
							order_id: parseInt(orderId, 10)
						}, false);
					}

					if (action === 'hold') {
						executeOrderUiAction(h, 'orders.hold', {
							order_id: parseInt(orderId, 10),
							order_ids: [parseInt(orderId, 10)]
						}, false);
					}

					if (action === 'note') {
						h.openNoteModal(function(note) {
							executeOrderUiAction(h, 'orders.add_note', {
								order_id: parseInt(orderId, 10),
								order_ids: [parseInt(orderId, 10)],
								note: note
							}, false);
						});
					}

					if (action === 'status') {
						h.openStatusModal(function(status) {
							executeOrderUiAction(h, 'orders.update_status', {
								order_id: parseInt(orderId, 10),
								order_ids: [parseInt(orderId, 10)],
								status: status
							}, false);
						});
					}

					if (action === 'write_email') {
						h.openTextModal('Write customer email', 'What should the email say?', function(instruction) {
							executeOrderUiAction(h, 'orders.compose_customer_email', {
								order_id: parseInt(orderId, 10),
								instruction: instruction
							}, false);
						});
					}

					if (action === 'refund_preview') {
						executeOrderUiAction(h, 'orders.refund_preview', {
							order_id: parseInt(orderId, 10)
						}, false);
					}

					if (action === 'load_email_drafts') {
						executeOrderUiAction(h, 'orders.get_customer_email_drafts', {
							order_id: parseInt(orderId, 10)
						}, false);
					}
				};
			});

			return true;
		}

		return false;
	}

	window.YoRoOrderUI = {
		init: init
	};
})();
