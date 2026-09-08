(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml,
			formatDateTime: helpers.formatDateTime,
			renderDebugJson: helpers.renderDebugJson,
			shouldShowDebugJson: helpers.shouldShowDebugJson,
			getConversationState: helpers.getConversationState,
			addAssistantMessage: helpers.addAssistantMessage,
			addLoadingMessage: helpers.addLoadingMessage,
			updateConversationState: helpers.updateConversationState,
			openModal: helpers.openModal,
				openTextModal: helpers.openTextModal,
				openNoteModal: helpers.openNoteModal,
				openStatusModal: helpers.openStatusModal,
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
			renderReportResult: function(data) {
				return renderReportResult(data, h);
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

	function displayLabel(value, options) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.labelFor === 'function') {
			return labels.labelFor(value, options);
		}

		return String(value || '').replace(/[_-]+/g, ' ');
	}

	function displayText(value) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.formatText === 'function') {
			return labels.formatText(value);
		}

		return String(value || '');
	}

	function displayKeyLabel(value) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.keyLabel === 'function') {
			return labels.keyLabel(value);
		}

		return displayLabel(value, { key: true });
	}

	function displayReportValue(value, locale) {
		if (value === undefined || value === null) {
			return '';
		}

		return locale ? String(value) : displayText(String(value));
	}

	function productStockStatusLabel(product) {
		product = product || {};

		return product.stock_status_label || displayLabel(product.stock_status || '');
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
				const rawStatus = String(status || '').trim();
				status = rawStatus
					.toLowerCase()
					.replace(/^wc-/, '')
					.replace(/_/g, '-')
					.replace(/\s+/g, '-');
				locale = normalizeLocale(locale);

				const labelsByLocale = {
					en: {
						pending: 'Pending payment',
						processing: 'Processing',
						completed: 'Completed',
						'on-hold': 'On hold',
						cancelled: 'Cancelled',
						canceled: 'Cancelled',
						refunded: 'Refunded',
						failed: 'Failed',
						'request-cancel': 'Request cancellation'
					},
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

				return labels[status] || rawStatus.replace(/^wc-/, '').replace(/[_-]/g, ' ').replace(/\b\w/g, function(char) {
					return char.toUpperCase();
				});
			}

			function orderDisplayStatus(order, locale) {
				order = order || {};

				return order.status_label || orderStatusLabel(order.status || '', locale);
			}

						function localizedWindowLabel(windowInfo, locale) {
			const key = String((windowInfo && windowInfo.key) || '').toLowerCase();
			const fallback = String((windowInfo && windowInfo.label) || '').trim();
			const labels = {
				es: { today: 'hoy', last_24_hours: 'últimas 24 horas', this_week: 'esta semana', this_month: 'este mes', this_year: 'este año', last_7_days: 'los últimos 7 días', last_30_days: 'los últimos 30 días', last_year: 'el año pasado' },
				fr: { today: 'aujourd’hui', last_24_hours: 'les dernières 24 heures', this_week: 'cette semaine', this_month: 'ce mois-ci', this_year: 'cette année', last_7_days: 'les 7 derniers jours', last_30_days: 'les 30 derniers jours', last_year: 'l’année dernière' },
				de: { today: 'heute', last_24_hours: 'die letzten 24 Stunden', this_week: 'diese Woche', this_month: 'diesen Monat', this_year: 'dieses Jahr', last_7_days: 'die letzten 7 Tage', last_30_days: 'die letzten 30 Tage', last_year: 'letztes Jahr' },
				ja: { today: '今日', last_24_hours: '過去24時間', this_week: '今週', this_month: '今月', this_year: '今年', last_7_days: '過去7日間', last_30_days: '過去30日間', last_year: '昨年' },
				th: { today: 'วันนี้', last_24_hours: '24 ชั่วโมงที่ผ่านมา', this_week: 'สัปดาห์นี้', this_month: 'เดือนนี้', this_year: 'ปีนี้', last_7_days: '7 วันที่ผ่านมา', last_30_days: '30 วันที่ผ่านมา', last_year: 'ปีที่แล้ว' },
				id: { today: 'hari ini', last_24_hours: '24 jam terakhir', this_week: 'minggu ini', this_month: 'bulan ini', this_year: 'tahun ini', last_7_days: '7 hari terakhir', last_30_days: '30 hari terakhir', last_year: 'tahun lalu' },
				pt: { today: 'hoje', last_24_hours: 'últimas 24 horas', this_week: 'esta semana', this_month: 'este mês', this_year: 'este ano', last_7_days: 'últimos 7 dias', last_30_days: 'últimos 30 dias', last_year: 'ano passado' },
				ar: { today: 'اليوم', last_24_hours: 'آخر 24 ساعة', this_week: 'هذا الأسبوع', this_month: 'هذا الشهر', this_year: 'هذا العام', last_7_days: 'آخر 7 أيام', last_30_days: 'آخر 30 يوما', last_year: 'العام الماضي' },
				vi: { today: 'hôm nay', last_24_hours: '24 giờ qua', this_week: 'tuần này', this_month: 'tháng này', this_year: 'năm nay', last_7_days: '7 ngày qua', last_30_days: '30 ngày qua', last_year: 'năm trước' }
			};
			locale = normalizeLocale(locale);
			if (labels[locale] && labels[locale][key]) {
				return labels[locale][key];
			}

			const relativeMatch = key.match(/^last_(\d+)_(hours|days|weeks|months|years)$/);

			if (relativeMatch) {
				const value = relativeMatch[1];
				const unit = relativeMatch[2];

				if (locale === 'vi') {
					const viUnits = { hours: 'giờ', days: 'ngày', weeks: 'tuần', months: 'tháng', years: 'năm' };
					return value + ' ' + (viUnits[unit] || unit) + ' qua';
				}

				return 'last ' + value + ' ' + unit.replace(/s$/, '') + (value === '1' ? '' : 's');
			}

			const monthMatch = key.match(/^month_(\d{4})_(\d{2})$/);

			if (monthMatch) {
				return locale === 'vi' ? ('tháng ' + parseInt(monthMatch[2], 10) + '/' + monthMatch[1]) : fallback;
			}

			const rangeMatch = key.match(/^range_(\d{4})_(\d{2})_(\d{2})_to_(\d{4})_(\d{2})_(\d{2})$/);

			if (rangeMatch) {
				if (locale === 'vi') {
					return rangeMatch[3] + '/' + rangeMatch[2] + '/' + rangeMatch[1] + ' đến ' + rangeMatch[6] + '/' + rangeMatch[5] + '/' + rangeMatch[4];
				}

				return rangeMatch[1] + '-' + rangeMatch[2] + '-' + rangeMatch[3] + ' to ' + rangeMatch[4] + '-' + rangeMatch[5] + '-' + rangeMatch[6];
			}

			const quarterMatch = key.match(/^quarter_(\d{4})_q([1-4])$/);

			if (quarterMatch) {
				return locale === 'vi' ? ('quý ' + quarterMatch[2] + '/' + quarterMatch[1]) : ('Q' + quarterMatch[2] + ' ' + quarterMatch[1]);
			}

			const yearMatch = key.match(/^year_(\d{4})$/);

			if (yearMatch) {
				return yearMatch[1];
			}

			return fallback;
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

			return result.summary || 'Operations report';
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

			return result.summary || 'Sales report';
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
			return displayLabel(normalized);
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

	function formatWindowRange(windowInfo, h, locale) {
		windowInfo = windowInfo || {};

		const dateStart = formatWindowDateOnly(windowInfo.date_from || extractDateOnly(windowInfo.start), locale);
		const dateEnd = formatWindowDateOnly(windowInfo.date_to || extractDateOnly(windowInfo.end), locale);

		if (dateStart && dateEnd) {
			return dateStart + ' → ' + dateEnd;
		}

		const start = formatDateTime(windowInfo.start, windowInfo.start_ts, h);
		const end = formatDateTime(windowInfo.end, windowInfo.end_ts, h);

		if (start && end) {
			return start + ' → ' + end;
		}

		return start || end || '';
	}

	function extractDateOnly(value) {
		const match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})/);

		return match ? match[1] : '';
	}

	function formatWindowDateOnly(value, locale) {
		const match = String(value || '').match(/^([12][0-9]{3})-([0-1][0-9])-([0-3][0-9])$/);

		if (!match) {
			return '';
		}

		const year = parseInt(match[1], 10);
		const month = parseInt(match[2], 10);
		const day = parseInt(match[3], 10);

		if (!year || month < 1 || month > 12 || day < 1 || day > 31) {
			return '';
		}

		try {
			const fallbackLocale = document.documentElement && document.documentElement.lang ? document.documentElement.lang : 'en-US';
			const formatter = new Intl.DateTimeFormat(String(locale || fallbackLocale || 'en-US'), {
				day: 'numeric',
				month: 'short',
				year: 'numeric'
			});

			return formatter.format(new Date(Date.UTC(year, month - 1, day, 12, 0, 0)));
		} catch (error) {
			return value;
		}
	}

	function normalizeActionCommandText(command) {
		return String(command || '').trim().replace(/\s+/g, ' ');
	}

	function commandTextFromActionSpec(actionSpec) {
		return normalizeActionCommandText(
			actionSpec && typeof actionSpec === 'object'
				? actionSpec.command
				: actionSpec
		);
	}

	function structuredDirectActionFromSpec(actionSpec) {
		if (!actionSpec || typeof actionSpec !== 'object') {
			return null;
		}

		const action = normalizeActionCommandText(actionSpec.action || actionSpec.name || '');

		if (!action) {
			return null;
		}

		return {
			action: action,
			params: actionSpec.params && typeof actionSpec.params === 'object' ? actionSpec.params : {},
			preview: !!actionSpec.preview
		};
	}

	function renderDirectActionButton(label, actionSpec, h, className) {
		const text = commandTextFromActionSpec(actionSpec);
		const classes = className || 'button button-small';
		const directAction = structuredDirectActionFromSpec(actionSpec);

		if (!directAction && !text) {
			return '';
		}

		let html = '<button type="button" class="' + h.escapeHtml(classes + (directAction ? ' yoro-direct-action' : ' yoro-clarify-option')) + '"';

		if (directAction) {
			html += ' data-direct-action="' + h.escapeHtml(directAction.action) + '"';
			html += ' data-direct-params="' + h.escapeHtml(JSON.stringify(directAction.params || {})) + '"';
			html += directAction.preview ? ' data-direct-preview="1"' : '';
		} else {
			html += ' data-command="' + h.escapeHtml(text) + '"';
		}

		html += '>' + h.escapeHtml(label || text || 'Open') + '</button>';

		return html;
	}

	function executeOrderUiAction(h, action, params, preview) {
		if (!action || !h.executeActionDirect) {
			return;
		}

		const loading = h.addLoadingMessage ? h.addLoadingMessage() : null;

		h.executeActionDirect(action, params || {}, false, !!preview, 'order_ui', '')
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
		const locale = getResultLocale(data);

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
			html += '<span class="yoro-status-badge">' + h.escapeHtml(orderDisplayStatus(order, locale)) + '</span>';
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
		if (!result || (!result.assistant_summary && !(result.meta && result.meta.assumption))) {
			return '';
		}

		locale = normalizeLocale(locale || '');
		if (locale && ['en', 'vi'].indexOf(locale) === -1) {
			return '';
		}

		const assistantSummary = String(result.assistant_summary || '').trim();
		const assumption = result.meta && result.meta.assumption ? result.meta.assumption : null;
		const assumptionMessage = assumption && assumption.message ? String(assumption.message).trim() : '';
		let body = '';

		if (assistantSummary && assistantSummary !== String(result.summary || '').trim()) {
			body += '<p>' + h.escapeHtml(assistantSummary) + '</p>';
		}

		if (assumptionMessage) {
			body += '<p class="yoro-result-subtle">' + h.escapeHtml(assumptionMessage) + '</p>';
		}

		if (!body) {
			return '';
		}

		return '<div class="yoro-result-notice yoro-result-success">' + body + '</div>';
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
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'needsAttention', 'Needs attention')) + '</span><strong>' + h.escapeHtml(String(needsAttentionCount)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'needsAttentionSubtle', 'Pending payment, On hold, or Failed')) + '</small></div>';
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'combinedTotal', 'Combined total')) + '</span><strong>' + h.escapeHtml(combinedTotal.toFixed(2) + ' ' + getOrdersListCurrency(orders)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'combinedTotalSubtle', 'Across visible results')) + '</small></div>';
		html += '<div><span>' + h.escapeHtml(orderUiText(locale, 'averageAge', 'Average age')) + '</span><strong>' + h.escapeHtml(formatAgeHoursForLocale(averageAge, locale)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(orderUiText(locale, 'visibleResults', 'Visible results')) + '</small></div>';
		html += '</div>';

		html += '<div class="yoro-bulk-actions">';
		html += '<button type="button" class="button yoro-run-hold">' + h.escapeHtml(orderUiText(locale, 'hold', 'Hold')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-details">' + h.escapeHtml(orderUiText(locale, 'viewDetails', 'View details')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-add-note">' + h.escapeHtml(orderUiText(locale, 'addNote', 'Add note')) + '</button>';
		html += '<button type="button" class="button yoro-bulk-update-status">' + h.escapeHtml(orderUiText(locale, 'updateStatus', 'Update status')) + '</button>';
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
			html += '<td><span class="yoro-status-badge">' + h.escapeHtml(orderDisplayStatus(order, locale)) + '</span><div class="yoro-result-subtle">' + h.escapeHtml(caseLabel) + '</div></td>';
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
			firstOrder ? { label: orderUiText(locale, 'showFirstOrder', 'Show first order'), command: 'Show order #' + firstOrder.id + ' details' } : null,
			action === 'orders.find_by_customer' && (result.email || result.phone)
				? { label: orderUiText(locale, 'customerProfile', 'Customer profile'), command: buildCustomerSummaryCommand(result) }
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
			return 'Look up customer ' + result.email;
		}
		if (result && result.phone) {
			return 'Look up customer ' + result.phone;
		}
		return 'Look up customer';
	}

	function renderCustomerSummaryResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const locale = getResultLocale(data);
		const customer = result.customer && typeof result.customer === 'object' ? result.customer : {};
		const related = result.related && typeof result.related === 'object' ? result.related : {};
		const relatedOrders = Array.isArray(related.recent_orders) ? related.recent_orders : [];
		const legacyOrders = Array.isArray(result.orders) ? result.orders : [];
		const orders = relatedOrders.length ? relatedOrders : legacyOrders;
		const customerMeta = customer.meta && typeof customer.meta === 'object' ? customer.meta : {};
		const statusCounts = customerMeta.status_counts || result.status_counts || {};
		const statusCountLabels = result.status_count_labels || {};
		const paymentMethods = customerMeta.payment_methods || result.payment_methods || {};
		const latestOrderId = result.latest_order_id || 0;
		const orderCount = parseInt(customer.orders_count || result.count || orders.length || 0, 10) || 0;
		const customerName = customer.name || result.customer_name || '';
		const customerEmail = customer.email || result.email || '';
		const customerPhone = customer.phone || result.phone || '';
		const customerId = customer.id || result.customer_id || 0;
		const customerEditUrl = customer.edit_url || result.customer_edit_url || '';
		const customerSegments = Array.isArray(customer.segments) ? customer.segments : [];
		const customerLabel = customerName || customerEmail || customerPhone || 'Customer profile';
		const lifetimeValue = customer.lifetime_value || result.total_spent || '0.00';
		const currency = customer.currency || result.currency || '';
		const firstOrder = customer.first_order_at || formatDateTime(result.first_order_date, result.first_order_date_ts, h) || '';
		const lastOrder = customer.last_order_at || formatDateTime(result.last_order_date, result.last_order_date_ts, h) || '';

		function labelize(key) {
			return displayKeyLabel(key);
		}

		function customerProfileSummaryText() {
			const orderLabel = orderCount === 1 ? 'related order' : 'related orders';

			if (customerName) {
				return 'Found customer profile for ' + customerName + ' with ' + orderCount + ' ' + orderLabel + '.';
			}

			return 'Found this customer profile with ' + orderCount + ' ' + orderLabel + '.';
		}

		function customerProfileIntroText() {
			const orderLabel = orderCount === 1 ? 'related order' : 'related orders';

			if (customerName) {
				return 'I found the customer profile for ' + customerName + ' with ' + orderCount + ' ' + orderLabel + '.';
			}

			return 'I found this customer profile with ' + orderCount + ' ' + orderLabel + '.';
		}

		function isLegacyCustomerLookupCopy(value) {
			const text = String(value || '').toLowerCase();

			return text.indexOf('customer summary') !== -1
				|| /^found\s+\d+\s+orders\s+for\s+this\s+customer/.test(text);
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
				html += '<td>' + h.escapeHtml(statusCountLabels[key] || orderStatusLabel(key, getResultLocale(data))) + '</td>';
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

		function customerInitials() {
			const source = String(customerName || customerEmail || customerPhone || 'Customer').trim();
			const normalized = source.indexOf('@') !== -1 ? source.split('@')[0] : source;
			const parts = normalized.split(/\s+/).filter(Boolean);
			const initials = parts.slice(0, 2).map(function(part) {
				return part.charAt(0);
			}).join('');

			return (initials || 'C').toUpperCase();
		}

		const customerIdLabel = customerId ? String(customerId) : 'Guest';
		const customerSegmentLabels = customerSegments.length ? customerSegments.map(labelize) : [];
		const displaySummary = result.summary && !isLegacyCustomerLookupCopy(result.summary)
			? result.summary
			: customerProfileSummaryText();
		const introResult = Object.assign({}, result, {
			summary: displaySummary
		});

		if (result.assistant_summary && isLegacyCustomerLookupCopy(result.assistant_summary)) {
			introResult.assistant_summary = customerProfileIntroText();
		}

		let html = renderAssistantIntro(introResult, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(displaySummary) + '</strong>';

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Customer profile</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(customerLabel) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(orderCount) + ' related orders</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Lifetime value</span><strong>' + h.escapeHtml(lifetimeValue) + ' ' + h.escapeHtml(currency) + '</strong></div>';
		html += '<div><span>Related orders</span><strong>' + h.escapeHtml(orderCount) + '</strong></div>';
		html += '<div><span>First order</span><strong>' + h.escapeHtml(firstOrder || '—') + '</strong></div>';
		html += '<div><span>Last order</span><strong>' + h.escapeHtml(lastOrder || '—') + '</strong></div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Profile snapshot</h4>';
		html += '<div class="yoro-customer-profile-snapshot">';
		html += '<div class="yoro-customer-profile-primary">';
		html += '<div class="yoro-customer-profile-avatar" aria-hidden="true">' + h.escapeHtml(customerInitials()) + '</div>';
		html += '<div class="yoro-customer-profile-copy">';
		html += '<strong class="yoro-customer-profile-name">' + h.escapeHtml(customerName || customerLabel) + '</strong>';
		html += '<div class="yoro-customer-profile-meta">';
		html += '<span>' + h.escapeHtml(customerId ? 'Customer #' + customerIdLabel : 'Guest customer') + '</span>';
		html += '</div>';
		html += '<div class="yoro-customer-profile-chips">';
		if (customerSegmentLabels.length) {
			customerSegmentLabels.forEach(function(segment) {
				html += '<span class="yoro-customer-profile-chip">' + h.escapeHtml(segment) + '</span>';
			});
		} else {
			html += '<span class="yoro-customer-profile-chip yoro-customer-profile-chip-muted">No segment</span>';
		}
		html += '</div>';
		html += '</div>';
		if (customerId && customerEditUrl) {
			html += '<div class="yoro-customer-profile-actions">';
			html += '<a class="button button-small" href="' + h.escapeHtml(customerEditUrl) + '" target="_blank" rel="noopener noreferrer">Edit user</a>';
			html += '</div>';
		}
		html += '</div>';
		html += '<div class="yoro-customer-profile-fields">';
		html += '<div class="yoro-customer-profile-field"><span>Email</span><strong>' + h.escapeHtml(customerEmail || '—') + '</strong></div>';
		html += '<div class="yoro-customer-profile-field"><span>Phone</span><strong>' + h.escapeHtml(customerPhone || '—') + '</strong></div>';
		html += '<div class="yoro-customer-profile-field"><span>Customer ID</span><strong>' + h.escapeHtml(customerIdLabel) + '</strong></div>';
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
			html += '<p class="yoro-result-subtle">No related orders found for this customer profile.</p>';
		} else {
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Payment</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
			orders.slice(0, 8).forEach(function(order) {
				html += '<tr>';
				html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(orderDisplayStatus(order, locale)) + '</span></td>';
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
			latestOrderId ? { label: 'Show latest order', command: 'Show order #' + latestOrderId + ' details' } : null,
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

	function getUnifiedReportFromResult(result, data, locale) {
		if (result && result.report && typeof result.report === 'object') {
			return result.report;
		}

		return null;
	}

	function renderUnifiedReportResult(data, h, locale) {
		const result = data.result || {};
		const report = getUnifiedReportFromResult(result, data, locale);

		if (!report || (!report.headline && !Array.isArray(report.metrics))) {
			return '';
		}

		const parsed = data.parsed || {};
		const windowInfo = report.window || result.window || {};
		let html = renderAssistantIntro(result, h, locale);

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<div class="yoro-result-summary yoro-result-summary-meta">';
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
			html += '</div>';
		}

		html += renderUnifiedReportPanel(report, h, locale, windowInfo);
		html += h.renderDebugJson(data);

		return html;
	}

	function renderReportContractFallback(data, h, locale, title) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		let html = renderAssistantIntro(result, h, locale);

		html += '<div class="yoro-result-notice yoro-result-preview">';
		html += '<p>' + h.escapeHtml(result.summary || result.message || 'YoRo received a report response, but it was not in the current report format.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(title || 'Store report') + '</strong>';
		html += '<span class="yoro-result-subtle">The raw payload is available in debug mode so the report contract can be fixed at the source.</span>';

		if (shouldShowDecisionRoute(h) && parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function reportPayloadText(value, locale) {
		return locale ? String(value === undefined || value === null ? '' : value) : displayText(value);
	}

	// UI chrome only; report content and conversation language come from the API.
	function reportDetailText(text, locale) {
		const vi = {
			'Store report': 'Báo cáo cửa hàng', 'Overview': 'Tổng quan', 'Sales': 'Doanh số',
			'Orders': 'Đơn hàng', 'Customers': 'Khách hàng', 'Products': 'Sản phẩm',
			'Operations': 'Vận hành', 'Inventory': 'Tồn kho', 'Signals': 'Tín hiệu',
			'Good': 'Tốt', 'Watch': 'Theo dõi', 'Risk': 'Rủi ro',
			'Key takeaways': 'Điểm chính', 'Needs attention': 'Cần chú ý', 'Breakdown': 'Phân tích chi tiết',
			'Additional signals': 'Tín hiệu bổ sung',
			'Supporting commercial and operating signals for this window.': 'Các tín hiệu kinh doanh và vận hành bổ sung trong kỳ này.',
			'Order flow, backlog, and execution pressure': 'Luồng đơn hàng, đơn tồn và áp lực thực thi',
			'Revenue, orders, customers, and product demand': 'Doanh thu, đơn hàng, khách hàng và nhu cầu sản phẩm',
			'The broad store picture for this window': 'Bức tranh tổng quan cửa hàng trong kỳ này',
			'Patterns across orders, customers, products, and inventory': 'Các xu hướng trong đơn hàng, khách hàng, sản phẩm và tồn kho',
			'Up': 'Tăng', 'Down': 'Giảm', 'Flat': 'Không đổi', 'share': 'tỷ trọng'
		};
		return normalizeLocale(locale) === 'vi' && Object.prototype.hasOwnProperty.call(vi, text) ? vi[text] : text;
	}

	function renderUnifiedReportPanel(report, h, locale, windowInfo, completePresentation) {
		const detailLocale = completePresentation ? locale : '';
		const health = ['good', 'watch', 'risk'].indexOf(report.health) !== -1 ? report.health : 'watch';
		const metrics = filterApplicableReportItems(Array.isArray(report.metrics) ? report.metrics : [], report);
		const insights = filterApplicableReportItems(Array.isArray(report.insights) ? report.insights : [], report);
		const alerts = filterApplicableReportItems(Array.isArray(report.alerts) ? report.alerts : [], report);
		const recommendedActions = filterApplicableReportItems(Array.isArray(report.recommended_actions) ? report.recommended_actions : [], report);
		const drilldowns = filterApplicableReportItems(Array.isArray(report.drilldowns) ? report.drilldowns : [], report);
		const breakdowns = filterApplicableReportItems(Array.isArray(report.breakdowns) ? report.breakdowns : [], report);
		const profileFollowups = Array.isArray(report.profile_followups) ? report.profile_followups : [];
		const typeMeta = reportTypeMeta(report.type);

		let html = '<div class="yoro-report-card yoro-report-health-' + h.escapeHtml(health) + ' yoro-report-type-' + h.escapeHtml(typeMeta.key) + '" data-health="' + h.escapeHtml(health) + '" data-report-type="' + h.escapeHtml(typeMeta.key) + '">';
		html += '<div class="yoro-report-header">';
		html += '<div class="yoro-report-header-main">';
		html += '<div class="yoro-report-kicker">';
		html += '<span>' + h.escapeHtml(reportDetailText(typeMeta.surface, detailLocale)) + '</span>';
		html += '<span class="yoro-report-scope-badge">' + h.escapeHtml(reportDetailText(typeMeta.scope, detailLocale)) + '</span>';
		html += '</div>';
		html += '<strong class="yoro-report-headline">' + h.escapeHtml(report.headline || reportDetailText('Store report', detailLocale)) + '</strong>';
		if (typeMeta.description) {
			html += '<span class="yoro-report-scope-line">' + h.escapeHtml(reportDetailText(typeMeta.description, detailLocale)) + '</span>';
		}
		html += renderReportWindowMeta(windowInfo || report.window || {}, h, locale);
		html += '</div>';
		html += '<span class="yoro-report-health-badge">' + h.escapeHtml(reportDetailText(reportHealthLabel(health), detailLocale)) + '</span>';
		html += '</div>';

		html += renderReportHighlights(report, metrics, h, detailLocale);
		html += renderReportMetricGroups(metrics, report.metric_groups, h, report, detailLocale);
		html += renderReportBreakdowns(breakdowns, h, detailLocale, completePresentation);

		html += renderReportInsightList(reportDetailText('Key takeaways', detailLocale), insights, h, 'yoro-report-insights', detailLocale, completePresentation);
		html += renderReportInsightList(reportDetailText('Needs attention', detailLocale), alerts, h, 'yoro-report-alerts', detailLocale, completePresentation);
		html += renderReportProfileFollowups(profileFollowups, h);
		html += renderReportActionGroup('Next actions', recommendedActions, h);
		html += renderReportActionGroup('Related views', filterDuplicateReportActions(drilldowns, recommendedActions), h);
		html += '</div>';

		return html;
	}

	function renderReportWindowMeta(windowInfo, h, locale) {
		windowInfo = windowInfo || {};
		const parts = [];
		const label = windowInfo.label ? localizedWindowLabel(windowInfo, locale) : '';
		const range = formatWindowRange(windowInfo, h, locale);

		if (label) {
			parts.push(label);
		}

		if (range) {
			parts.push(range);
		}

		if (!parts.length) {
			return '';
		}

		return '<span class="yoro-report-window-line">' + parts.map(function(part) {
			return h.escapeHtml(part);
		}).join(' · ') + '</span>';
	}

	function renderReportHighlights(report, metrics, h, locale) {
		const highlighted = selectReportHighlights(report, metrics);

		if (!highlighted.length) {
			return '';
		}

		let html = '<div class="yoro-report-highlights">';
		highlighted.forEach(function(metric) {
			const severity = ['positive', 'neutral', 'watch', 'risk'].indexOf(metric.severity) !== -1 ? metric.severity : 'neutral';
			const unit = metric.unit ? String(metric.unit) : '';
			html += '<div class="yoro-report-highlight yoro-report-severity-' + h.escapeHtml(severity) + '">';
			html += '<span class="yoro-report-highlight-label">' + h.escapeHtml(reportPayloadText(metric.label || metric.key || '', locale)) + '</span>';
			html += '<strong class="yoro-report-highlight-value">' + h.escapeHtml(displayReportValue(metric.value, locale)) + '</strong>';
			if (unit) {
				html += '<span class="yoro-report-highlight-unit">' + h.escapeHtml(unit) + '</span>';
			}
			html += renderReportMetricTrend(metric, h, locale);
			html += '</div>';
		});
		html += '</div>';

		return html;
	}

	function selectReportHighlights(report, metrics) {
		if (!Array.isArray(metrics) || !metrics.length) {
			return [];
		}

		const keys = reportHighlightKeys(report && report.type);
		const selected = [];
		const used = {};

		keys.forEach(function(key) {
			const metric = metrics.find(function(item) {
				return item && item.key === key;
			});

			if (metric && !used[metric.key]) {
				selected.push(metric);
				used[metric.key] = true;
			}
		});

		if (selected.length < 3) {
			metrics.forEach(function(metric) {
				if (selected.length >= 3 || !metric || used[metric.key]) {
					return;
				}

				selected.push(metric);
				used[metric.key] = true;
			});
		}

		return selected.slice(0, 4);
	}

	function reportHighlightKeys(type) {
		switch (String(type || '')) {
			case 'business':
				return ['gross_sales', 'net_sales', 'orders', 'average_order_value'];
			case 'operations':
				return ['attention_items', 'open_backlog', 'new_orders', 'processing_backlog'];
			case 'store_insights':
				return ['orders', 'gross_sales', 'inventory_risk', 'unique_customers'];
			case 'store_overview':
			default:
				return ['gross_sales', 'new_orders', 'attention_items', 'main_pressure'];
		}
	}

	function renderReportMetricGroups(metrics, metricGroups, h, report, locale) {
		if (!Array.isArray(metrics) || !metrics.length) {
			return '';
		}

		const reportType = String(report && report.type || '').toLowerCase();
		const highlighted = {};
		selectReportHighlights(report || {}, metrics).forEach(function(metric) {
			if (metric && metric.key) {
				highlighted[metric.key] = true;
			}
		});
		const groupsByDomain = {};

		if (reportType === 'store_overview') {
			groupsByDomain.overview = {
				domain: 'overview',
				label: reportDetailText('Additional signals', locale),
				description: reportDetailText('Supporting commercial and operating signals for this window.', locale),
				priority: 20,
				metrics: []
			};
		} else {
			(Array.isArray(metricGroups) ? metricGroups : []).forEach(function(group) {
				const domain = normalizeReportMetricDomain(group && group.domain, '');

				if (!domain || groupsByDomain[domain]) {
					return;
				}

				groupsByDomain[domain] = {
					domain: domain,
					label: group.label || reportMetricDomainLabel(domain, locale),
					description: group.description || '',
					priority: parseInt(group.priority, 10) || reportMetricDomainPriority(domain),
					metrics: []
				};
			});
		}

		metrics.forEach(function(metric) {
			if (!shouldRenderReportDetailMetric(metric, reportType, highlighted)) {
				return;
			}

			const domain = reportType === 'store_overview'
				? 'overview'
				: normalizeReportMetricDomain(metric && metric.domain, metric && metric.key);

			if (!groupsByDomain[domain]) {
				groupsByDomain[domain] = {
					domain: domain,
					label: reportMetricDomainLabel(domain, locale),
					description: '',
					priority: reportMetricDomainPriority(domain),
					metrics: []
				};
			}

			groupsByDomain[domain].metrics.push(metric);
		});

		const groups = Object.keys(groupsByDomain)
			.map(function(domain) {
				return groupsByDomain[domain];
			})
			.filter(function(group) {
				return group.metrics.length > 0;
			})
			.sort(function(a, b) {
				return (a.priority || 50) - (b.priority || 50);
			});

		if (!groups.length) {
			return '';
		}

		let html = '<div class="yoro-report-metric-groups">';
		groups.forEach(function(group) {
			group.metrics.sort(function(a, b) {
				return (parseInt((a || {}).priority, 10) || 50) - (parseInt((b || {}).priority, 10) || 50);
			});
			html += '<div class="yoro-report-metric-group yoro-report-metric-group-' + h.escapeHtml(group.domain) + '">';
			html += '<div class="yoro-report-metric-group-head">';
			html += '<h4>' + h.escapeHtml(group.label || reportMetricDomainLabel(group.domain, locale)) + '</h4>';
			if (group.description) {
				html += '<span>' + h.escapeHtml(group.description) + '</span>';
			}
			html += '</div>';
			html += '<div class="yoro-report-metrics">';
			group.metrics.forEach(function(metric) {
				html += renderReportMetric(metric, h, !!highlighted[metric.key], locale);
			});
			html += '</div>';
			html += '</div>';
		});
		html += '</div>';

		return html;
	}

	function shouldRenderReportDetailMetric(metric, reportType, highlighted) {
		if (!metric || !metric.key) {
			return false;
		}

		if (reportType === 'store_overview' && highlighted && highlighted[metric.key]) {
			return false;
		}

		return true;
	}

	function filterApplicableReportItems(items, report) {
		if (!Array.isArray(items) || !items.length) {
			return [];
		}

		return items.filter(function(item) {
			return isReportItemApplicable(item, report);
		});
	}

	function isReportItemApplicable(item, report) {
		const gates = getReportApplicabilityGates(report);
		const domains = reportApplicabilityDomainsForItem(item);

		if (!domains.length) {
			return true;
		}

		return domains.every(function(domain) {
			const gate = gates[domain];

			if (!gate || typeof gate !== 'object' || typeof gate.applicable === 'undefined') {
				return true;
			}

			return !!gate.applicable;
		});
	}

	function getReportApplicabilityGates(report) {
		const direct = report && report.applicability && typeof report.applicability === 'object'
			? report.applicability
			: {};
		const profile = report && report.profile_context && report.profile_context.settings_applicability && typeof report.profile_context.settings_applicability === 'object'
			? report.profile_context.settings_applicability
			: {};

		return Object.assign({}, profile, direct);
	}

	function reportApplicabilityDomainsForItem(item) {
		item = item || {};
		const text = [
			item.key,
			item.label,
			item.value,
			item.title,
			item.message,
			item.topic,
			item.domain,
			item.command,
			item.description,
			item.reason
		].map(function(value) {
			return String(value || '').toLowerCase();
		}).join(' ');
		const domains = [];

		if (/(shipping|ship|shippable|delivery|tracking|fulfillment)/.test(text)) {
			domains.push('shipping');
		}

		if (/(inventory|stock|backorder|restock|out of stock|low stock)/.test(text)) {
			domains.push('inventory');
		}

		if (/(tax|vat|gst)/.test(text)) {
			domains.push('tax');
		}

		if (/(discount|coupon)/.test(text)) {
			domains.push('coupons');
		}

		if (/(payment|gateway)/.test(text)) {
			domains.push('payments');
		}

		return domains.filter(function(domain, index) {
			return domains.indexOf(domain) === index;
		});
	}

	function renderReportMetric(metric, h, highlighted, locale) {
		metric = metric || {};
		const severity = ['positive', 'neutral', 'watch', 'risk'].indexOf(metric.severity) !== -1 ? metric.severity : 'neutral';
		const unit = metric.unit ? String(metric.unit) : '';
		const classes = [
			'yoro-report-metric',
			'yoro-report-severity-' + severity
		];

		if (highlighted) {
			classes.push('yoro-report-metric-highlighted');
		}

		let html = '<div class="' + h.escapeHtml(classes.join(' ')) + '">';
		html += '<span class="yoro-report-metric-label">' + h.escapeHtml(reportPayloadText(metric.label || metric.key || '', locale)) + '</span>';
		html += '<strong class="yoro-report-metric-value">' + h.escapeHtml(displayReportValue(metric.value, locale)) + '</strong>';
		if (unit) {
			html += '<span class="yoro-report-metric-unit">' + h.escapeHtml(unit) + '</span>';
		}
		html += renderReportMetricTrend(metric, h, locale);
		html += '</div>';

		return html;
	}

	function renderReportMetricTrend(metric, h, locale) {
		metric = metric || {};
		const trend = ['up', 'down', 'flat'].indexOf(metric.trend) !== -1 ? metric.trend : '';
		const delta = metric.delta === undefined || metric.delta === null ? '' : String(metric.delta);

		if (!trend && !delta) {
			return '';
		}

		const label = delta || reportDetailText(trend.replace(/\b\w/g, function(chr) {
			return chr.toUpperCase();
		}), locale);

		return '<span class="yoro-report-metric-trend yoro-report-trend-' + h.escapeHtml(trend || 'neutral') + '">' + h.escapeHtml(label) + '</span>';
	}

	function renderReportBreakdowns(breakdowns, h, locale, completePresentation) {
		if (!Array.isArray(breakdowns) || !breakdowns.length) {
			return '';
		}

		let sections = '';
		breakdowns
			.slice()
			.sort(function(a, b) {
				return (parseInt((a || {}).priority, 10) || 50) - (parseInt((b || {}).priority, 10) || 50);
			})
			.slice(0, completePresentation ? breakdowns.length : 3)
			.forEach(function(breakdown) {
				const items = Array.isArray(breakdown && breakdown.items) ? breakdown.items : [];

				if (!items.length) {
					return;
				}

				const domain = normalizeReportMetricDomain(breakdown.domain, breakdown.key || breakdown.label);
				let section = '<div class="yoro-report-breakdown yoro-report-breakdown-' + h.escapeHtml(domain) + '">';
				section += '<div class="yoro-report-breakdown-head">';
				section += '<div>';
				section += '<h4>' + h.escapeHtml(reportPayloadText(breakdown.label || reportDetailText('Breakdown', locale), locale)) + '</h4>';
				if (breakdown.description) {
					section += '<span>' + h.escapeHtml(reportPayloadText(breakdown.description, locale)) + '</span>';
				}
				section += '</div>';
				section += renderReportDomainBadge(domain, h, locale);
				section += '</div>';
				section += '<div class="yoro-report-breakdown-list">';
				items.slice(0, completePresentation ? items.length : 5).forEach(function(item) {
					section += renderReportBreakdownItem(item, h, locale);
				});
				section += '</div>';
				section += '</div>';
				sections += section;
			});

		if (!sections) {
			return '';
		}

		return '<div class="yoro-report-breakdowns">' + sections + '</div>';
	}

	function renderReportBreakdownItem(item, h, locale) {
		item = item || {};
		const value = displayReportValue(item.value, locale);
		const unit = item.unit ? String(item.unit) : '';
		const subvalue = item.subvalue ? reportPayloadText(item.subvalue, locale) : '';
		const share = item.share ? String(item.share) : '';
		let html = '<div class="yoro-report-breakdown-item">';
		html += '<div class="yoro-report-breakdown-main">';
		html += '<strong>' + h.escapeHtml(reportPayloadText(item.label || '', locale)) + '</strong>';
		html += renderReportBreakdownMeta(item, h);
		html += '</div>';
		html += '<div class="yoro-report-breakdown-value">';
		html += '<strong>' + h.escapeHtml(value) + '</strong>';
		if (unit) {
			html += '<span>' + h.escapeHtml(unit) + '</span>';
		}
		if (subvalue || share) {
			html += '<small>' + h.escapeHtml([subvalue, share ? (share + ' ' + reportDetailText('share', locale)) : ''].filter(Boolean).join(' · ')) + '</small>';
		}
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderReportBreakdownMeta(item, h) {
		const meta = item && item.meta && typeof item.meta === 'object' ? item.meta : {};
		const parts = [];

		if (meta.category) {
			parts.push(String(meta.category));
		} else if (Array.isArray(meta.categories) && meta.categories.length) {
			parts.push(meta.categories.slice(0, 2).join(', '));
		}

		if (meta.sku) {
			parts.push('SKU ' + meta.sku);
		}

		if (meta.product_lines) {
			parts.push(meta.product_lines + ' product lines');
		}

		if (!parts.length) {
			return '';
		}

		return '<span>' + h.escapeHtml(parts.join(' · ')) + '</span>';
	}

	function normalizeReportMetricDomain(domain, key) {
		domain = String(domain || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');

		if (!domain || ['sales', 'orders', 'customers', 'products', 'operations', 'inventory', 'overview'].indexOf(domain) === -1) {
			domain = inferReportMetricDomain(key);
		}

		return ['sales', 'orders', 'customers', 'products', 'operations', 'inventory', 'overview'].indexOf(domain) !== -1 ? domain : 'overview';
	}

	function inferReportMetricDomain(key) {
		key = String(key || '').toLowerCase();

		if (/(sales|revenue|average_order_value|gross|net|completed_sales|refund|discount|tax|shipping)/.test(key)) {
			return 'sales';
		}

		if (/(customer|customers|buyer|follow-up|email|reply|support)/.test(key)) {
			return 'customers';
		}

		if (/(inventory|stock|backorder)/.test(key)) {
			return 'inventory';
		}

		if (/(backlog|attention|queue|pressure|fulfillment|pending|processing|on_hold)/.test(key)) {
			return 'operations';
		}

		if (/(product|products|item|items)/.test(key)) {
			return 'products';
		}

		if (/(order|orders)/.test(key)) {
			return 'orders';
		}

		return 'overview';
	}

	function reportMetricDomainLabel(domain, locale) {
		const labels = { sales: 'Sales', orders: 'Orders', customers: 'Customers', products: 'Products', operations: 'Operations', inventory: 'Inventory' };
		return reportDetailText(labels[domain] || 'Overview', locale);
	}

	function reportMetricDomainPriority(domain) {
		switch (domain) {
			case 'sales':
				return 10;
			case 'orders':
				return 20;
			case 'customers':
				return 30;
			case 'products':
				return 40;
			case 'operations':
				return 50;
			case 'inventory':
				return 60;
			default:
				return 90;
		}
	}

	function renderReportInsightList(title, items, h, className, locale, completePresentation) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let html = '<div class="yoro-report-section ' + h.escapeHtml(className || '') + '">';
		html += '<h4>' + h.escapeHtml(title) + '</h4>';
		html += '<div class="yoro-report-list">';
		sortReportItems(items).slice(0, completePresentation ? items.length : 4).forEach(function(item) {
			const severity = item && ['positive', 'neutral', 'watch', 'risk'].indexOf(item.severity) !== -1 ? item.severity : 'neutral';
			const domain = normalizeReportMetricDomain(item && item.domain, item && item.topic);
			html += '<div class="yoro-report-list-item yoro-report-severity-' + h.escapeHtml(severity) + '">';
			html += '<div class="yoro-report-list-item-head">';
			if (item && item.title) {
				html += '<strong>' + h.escapeHtml(item.title) + '</strong>';
			}
			html += renderReportDomainBadge(domain, h, locale);
			html += '</div>';
			if (item && item.message) {
				html += '<p>' + h.escapeHtml(item.message) + '</p>';
			}
			html += '</div>';
		});
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderReportProfileFollowups(items, h) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let html = '<div class="yoro-report-section yoro-report-profile-followups">';
		html += '<h4>Store profile</h4>';
		html += '<div class="yoro-report-profile-followup-list">';
		items.slice(0, 3).forEach(function(item) {
			if (!item || !item.question) {
				return;
			}

			const domain = normalizeReportMetricDomain(item.domain, item.key || '');
			html += '<div class="yoro-report-profile-followup">';
			html += '<div class="yoro-report-list-item-head">';
			html += '<strong>' + h.escapeHtml(item.question) + '</strong>';
			html += renderReportDomainBadge(domain, h);
			html += '</div>';
			if (item.reason) {
				html += '<p>' + h.escapeHtml(item.reason) + '</p>';
			}
			html += '</div>';
		});
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderReportActionGroup(title, items, h) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let cards = '';
		sortReportItems(items).slice(0, 4).forEach(function(item) {
			cards += renderReportActionCard(item, h);
		});

		if (!cards) {
			return '';
		}

		let html = '<div class="yoro-report-section yoro-report-actions">';
		html += '<h4>' + h.escapeHtml(title) + '</h4>';
		html += '<div class="yoro-report-action-grid">' + cards + '</div>';
		html += '</div>';

		return html;
	}

	function renderReportActionCard(item, h) {
		if (!item || !item.command) {
			return '';
		}

		const button = renderDirectActionButton(reportActionButtonLabel(item), item, h);

		if (!button) {
			return '';
		}

		const domain = normalizeReportMetricDomain(item.domain, item.label || item.command);
		let html = '<div class="yoro-report-action-card">';
		html += '<div class="yoro-report-action-copy">';
		html += '<div class="yoro-report-action-head">';
		html += '<strong>' + h.escapeHtml(item.label || item.command) + '</strong>';
		html += renderReportDomainBadge(domain, h);
		html += '</div>';
		if (item.reason) {
			html += '<p>' + h.escapeHtml(item.reason) + '</p>';
		}
		html += '</div>';
		html += '<div class="yoro-report-action-button">' + button + '</div>';
		html += '</div>';

		return html;
	}

	function reportActionButtonLabel(item) {
		const kind = String(item && item.kind ? item.kind : '').toLowerCase();
		const command = normalizeActionCommandText(item && item.command);

		if (kind === 'preview' || /^preview\b/i.test(command)) {
			return 'Preview';
		}

		if (kind === 'draft' || /^draft\b/i.test(command)) {
			return 'Draft';
		}

		if (kind === 'send' || /^send\b/i.test(command)) {
			return 'Send';
		}

		if (kind === 'execute' || /^run\b/i.test(command)) {
			return 'Run';
		}

		if (/^(show|open|review|check)\b/i.test(command)) {
			return 'Open';
		}

		return 'Run';
	}

	function renderReportDomainBadge(domain, h, locale) {
		domain = normalizeReportMetricDomain(domain, '');

		return '<span class="yoro-report-domain-badge yoro-report-domain-' + h.escapeHtml(domain) + '">' + h.escapeHtml(reportMetricDomainLabel(domain, locale)) + '</span>';
	}

	function sortReportItems(items) {
		return (items || []).slice().sort(function(a, b) {
			const priorityCompare = (parseInt((a || {}).priority, 10) || 50) - (parseInt((b || {}).priority, 10) || 50);

			if (priorityCompare !== 0) {
				return priorityCompare;
			}

			return reportSeverityWeight((a || {}).severity) - reportSeverityWeight((b || {}).severity);
		});
	}

	function reportSeverityWeight(severity) {
		switch (String(severity || 'neutral')) {
			case 'risk':
				return 10;
			case 'watch':
				return 20;
			case 'positive':
				return 30;
			default:
				return 40;
		}
	}

	function filterDuplicateReportActions(items, existingItems) {
		const seen = {};
		(existingItems || []).forEach(function(item) {
			if (item && item.command) {
				seen[normalizeActionCommandText(item.command).toLowerCase()] = true;
			}
		});

		return (items || []).filter(function(item) {
			const key = item && item.command ? normalizeActionCommandText(item.command).toLowerCase() : '';
			if (!key || seen[key]) {
				return false;
			}
			seen[key] = true;
			return true;
		});
	}

	function reportTypeMeta(type) {
		switch (String(type || '')) {
			case 'operations':
				return {
					key: 'operations',
					surface: 'Store report',
					scope: 'Operations',
					description: 'Order flow, backlog, and execution pressure'
				};
			case 'business':
				return {
					key: 'business',
					surface: 'Store report',
					scope: 'Sales',
					description: 'Revenue, orders, customers, and product demand'
				};
			case 'store_overview':
				return {
					key: 'store_overview',
					surface: 'Store report',
					scope: 'Overview',
					description: 'The broad store picture for this window'
				};
			case 'store_insights':
				return {
					key: 'store_insights',
					surface: 'Store report',
					scope: 'Signals',
					description: 'Patterns across orders, customers, products, and inventory'
				};
			default:
				return {
					key: 'overview',
					surface: 'Store report',
					scope: 'Overview',
					description: ''
				};
		}
	}

	function reportHealthLabel(health) {
		switch (String(health || '')) {
			case 'good':
				return 'Good';
			case 'risk':
				return 'Risk';
			case 'watch':
			default:
				return 'Watch';
		}
	}

	let reportPresentationSequence = 0;

	function renderReportResult(data, h) {
		if (data.result && data.result.report_presentation) {
			return renderReportPresentation(data, h);
		}
		const locale = getResultLocale(data);

		return renderUnifiedReportResult(data, h, locale)
			|| renderReportContractFallback(data, h, locale, 'Store report');
	}

	function renderReportPresentation(data, h) {
		const result = data.result || {};
		const p = result.report_presentation;
		const state = h.getConversationState ? h.getConversationState() : {};
		const valid = ['store.get_daily_briefing', 'store.get_business_summary', 'operations.get_summary', 'store.get_insights'].indexOf(p.action) !== -1
			&& p.version === 1 && typeof p.conversation_id === 'string' && p.action_event_id > 0
			&& p.result_id === p.conversation_id + ':' + p.action_event_id
			&& (!h.getConversationState || state.conversation_id === p.conversation_id)
			&& (!data.conversation_id || data.conversation_id === p.conversation_id)
			&& (!result.action || result.action === p.action);
		if (!valid) {
			return '<p class="yoro-report-unavailable">' + h.escapeHtml(p.state === 'unavailable' ? result.summary : '') + '</p>';
		}
		const id = 'yoro-report-details-' + (++reportPresentationSequence);
		const expanded = p.expanded === true;
		let html = '<div class="yoro-report-presentation" data-report-result-id="' + h.escapeHtml(p.result_id)
			+ '" data-conversation-id="' + h.escapeHtml(p.conversation_id) + '">';
		html += '<p class="yoro-report-summary">' + h.escapeHtml(p.summary || '') + '</p>';
		if (p.has_details === true && ['complete', 'partial', 'no_data'].indexOf(p.state) !== -1 && p.report && typeof p.report === 'object') {
			html += '<button type="button" class="button yoro-report-toggle" aria-expanded="' + expanded + '" aria-controls="' + id
				+ '" data-open-label="' + h.escapeHtml(p.open_label) + '" data-close-label="' + h.escapeHtml(p.close_label) + '">'
				+ h.escapeHtml(expanded ? p.close_label : p.open_label) + '</button>';
			html += '<div class="yoro-report-details" id="' + id + '"' + (expanded ? '' : ' hidden') + '>';
			html += renderUnifiedReportPanel(p.report, h, normalizeLocale(p.language) || getResultLocale(data), p.report.window || {}, true);
			html += '</div>';
		}
		return html + '</div>';
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

			html += '<div><span>' + h.escapeHtml(displayText(metric.label || '')) + '</span><strong>' + h.escapeHtml(displayReportValue(metric.value || 0)) + '</strong></div>';
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
				html += '<div class="yoro-action-list">';
				html += renderDirectActionButton(item.label || item.command, item, h);
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

		let html = '<div class="yoro-action-strip yoro-briefing-actions">';
		html += '<strong>' + h.escapeHtml(title || 'Next actions') + '</strong>';
		html += '<div class="yoro-action-list">';

		items.slice(0, 4).forEach(function (item) {
			if (!item || !item.command) {
				return;
			}

			html += renderDirectActionButton(item.label || item.command, item, h);
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
	html += '<div class="yoro-action-list">';
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
				action,
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
		html += '<div class="yoro-action-list">';
		worklist.actions.forEach(function (action, index) {
			if (!action || !action.command) {
				return;
			}
			html += renderDirectActionButton(
				action.label || action.command,
				action,
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
		if (!refreshCommand) {
			return null;
		}

		return {
			title: 'Refresh this work surface',
			message: 'Open the latest direct view for this queue before taking the next action.',
			detail: 'Step ' + (Math.max(0, normalizedCursor) + 1) + ' of ' + sequence.length + ' in the current recommendation path.',
			actions: [
				{
					label: 'Refresh queue',
					command: refreshCommand
				}
			]
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
				return 'Show store report for the last 7 days';
			case 'business_summary':
				return 'Show sales report for the last 7 days';
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

			html += '<div><span>' + h.escapeHtml(displayText(metric.label || '')) + '</span><strong>' + h.escapeHtml(displayReportValue(metric.value || 0)) + '</strong></div>';
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
				html += '<div class="yoro-action-list">';
				item.actions.slice(0, 3).forEach(function (action) {
					if (!action || (!action.command && !action.href)) {
						return;
					}
					if (action.href) {
						html += '<a class="button button-small" href="' + h.escapeHtml(action.href) + '" target="_blank" rel="noopener noreferrer">';
						html += h.escapeHtml(action.label || action.href);
						html += '</a>';
					} else {
						html += renderDirectActionButton(action.label || action.command, action, h);
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

	function getQueueMatchCount(result) {
		return parseInt(result && Object.prototype.hasOwnProperty.call(result, 'queue_item_count') ? result.queue_item_count : (result && result.count ? result.count : 0), 10) || 0;
	}

	function getQueueVisibleCaseCount(result, itemKey) {
		const explicit = result && Object.prototype.hasOwnProperty.call(result, 'visible_case_count') ? result.visible_case_count : null;
		const keyed = result && itemKey && Object.prototype.hasOwnProperty.call(result, itemKey) && Array.isArray(result[itemKey])
			? result[itemKey].length
			: null;

		return parseInt(null !== explicit && undefined !== explicit ? explicit : (null !== keyed && undefined !== keyed ? keyed : 0), 10) || 0;
	}

	function renderQueueSectionHeading(queue, itemLabel, h) {
		queue = queue && typeof queue === 'object' ? queue : {};
		const count = parseInt(queue && queue.count ? queue.count : 0, 10) || 0;
		let html = '<div class="yoro-queue-section-head">';
		html += '<div>';
		html += '<h4>' + h.escapeHtml(displayText(queue.label || queue.key || 'Queue')) + '</h4>';
		if (queue.description) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(displayText(queue.description)) + '</p>';
		}
		html += '</div>';
		html += '<span class="yoro-queue-count">' + h.escapeHtml(count) + ' ' + h.escapeHtml(itemLabel || 'matches') + '</span>';
		html += '</div>';
		return html;
	}

	function renderQueueTable(columns, rowsHtml, h, className) {
		const tableClass = className ? String(className) : 'yoro-queue-table-orders';
		let html = '<div class="yoro-queue-table-scroll">';
		html += '<table class="yoro-orders-table yoro-queue-table ' + h.escapeHtml(tableClass) + '">';
		html += '<thead><tr>';
		(columns || []).forEach(function (column) {
			html += '<th>' + h.escapeHtml(column.label || '') + '</th>';
		});
		html += '</tr></thead><tbody>';
		html += rowsHtml || '';
		html += '</tbody></table>';
		html += '</div>';
		return html;
	}

	function renderOrderQueueSections(queues, tableConfig, h) {
		let html = '';
		const config = tableConfig && typeof tableConfig === 'object' ? tableConfig : {};
		const maxRows = typeof config.maxRows === 'number' ? config.maxRows : 0;
		const columns = Array.isArray(config.columns) ? config.columns : [];
		const tableClassName = config.tableClassName || 'yoro-queue-table-orders';

		(queues || []).forEach(function (queue) {
			const orders = Array.isArray(queue.orders) ? queue.orders : [];
			const visibleOrders = maxRows > 0 ? orders.slice(0, maxRows) : orders;
			let rowsHtml = '';
			html += '<div class="yoro-order-detail-section yoro-queue-section">';
			html += renderQueueSectionHeading(queue, 'matches', h);

			if (!orders.length) {
				html += '<p class="yoro-result-subtle yoro-queue-empty">' + h.escapeHtml(config.emptyMessage || 'No matching orders in this queue.') + '</p>';
				html += '</div>';
				return;
			}

			visibleOrders.forEach(function (order) {
				rowsHtml += config.renderRow(order, queue, h);
			});

			html += renderQueueTable(columns, rowsHtml, h, tableClassName);
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
			let rowsHtml = '';
			html += '<div class="yoro-order-detail-section yoro-queue-section">';
			html += renderQueueSectionHeading(queue, 'matches', h);

			if (!products.length) {
				html += '<p class="yoro-result-subtle yoro-queue-empty">No matching products in this queue.</p>';
				html += '</div>';
				return;
			}

			products.forEach(function (product) {
				const stock = product.stock_quantity === '' || product.stock_quantity === null
					? '—'
					: product.stock_quantity;

				rowsHtml += '<tr>';
				rowsHtml += '<td><strong>' + h.escapeHtml(product.name || ('#' + product.id)) + '</strong></td>';
				rowsHtml += '<td>' + h.escapeHtml(product.sku || '—') + '</td>';
				rowsHtml += '<td>' + h.escapeHtml(stock) + ' <span class="yoro-result-subtle">/ low ' + h.escapeHtml(product.low_stock_threshold || 0) + '</span></td>';
				rowsHtml += '<td><span class="yoro-status-badge">' + h.escapeHtml(productStockStatusLabel(product)) + '</span></td>';
				rowsHtml += '<td>' + h.escapeHtml(product.backorders_allowed ? 'Allowed' : 'No') + '</td>';
				rowsHtml += '<td class="yoro-row-actions">';
				if (product.edit_url) {
					rowsHtml += '<a class="button button-small" href="' + h.escapeHtml(product.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
				}
				rowsHtml += '</td>';
				rowsHtml += '</tr>';
			});

			html += renderQueueTable([
				{ label: 'Product' },
				{ label: 'SKU' },
				{ label: 'Stock' },
				{ label: 'Status' },
				{ label: 'Backorders' },
				{ label: 'Actions' }
			], rowsHtml, h, 'yoro-queue-table-products');
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
			let rowsHtml = '';
			html += '<div class="yoro-order-detail-section yoro-queue-section">';
			html += renderQueueSectionHeading(queue, 'matches', h);

			if (!products.length) {
				html += '<p class="yoro-result-subtle yoro-queue-empty">No matching products in this queue.</p>';
				html += '</div>';
				return;
			}

			visibleProducts.forEach(function (product) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const stockStatusLabel = productStockStatusLabel(product);
				const productMeta = [product.sku || '', stockStatusLabel].filter(Boolean).join(' · ');
				const caseTitle = [
					humanizeInventoryTopic(queueKey || ''),
					stockStatusLabel
				].filter(Boolean).join(' · ');

				rowsHtml += '<tr>';
				rowsHtml += '<td><strong>' + h.escapeHtml(product.name || ('#' + product.id)) + '</strong>';
				if (productMeta) {
					rowsHtml += '<div class="yoro-result-subtle">' + h.escapeHtml(productMeta) + '</div>';
				}
				rowsHtml += '</td>';
				rowsHtml += '<td><strong>' + h.escapeHtml(caseTitle || 'Inventory case') + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(displayText(buildInventoryRowCaseMessage(queueKey, queue.description || '', product))) + '</div></td>';
				rowsHtml += '<td>' + h.escapeHtml(formatInventoryRowStock(product, stockManagementEnabled, inventoryMode)) + '</td>';
				rowsHtml += '<td class="yoro-row-actions">';
				if (product.edit_url) {
					rowsHtml += '<a class="button button-small" href="' + h.escapeHtml(product.edit_url) + '" target="_blank" rel="noopener noreferrer">Open product</a>';
				}
				rowsHtml += '</td>';
				rowsHtml += '</tr>';
			});

			html += renderQueueTable([
				{ label: 'Product' },
				{ label: 'Case' },
				{ label: 'Stock' },
				{ label: 'Next action' }
			], rowsHtml, h, 'yoro-queue-table-inventory');
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
		const queueMatches = getQueueMatchCount(result);
		const visibleCases = getQueueVisibleCaseCount(result, 'orders');

		let html = renderQueueWorkbenchIntro({
			result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Operations attention queue',
				subtle: 'Orders that may need review next.',
				parserRule: parsed.parser_rule,
				title: 'Operations attention cases',
				contextLine: 'Top cases from each active queue.',
				badge: queueMatches + ' matches'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Queue matches', value: queueMatches },
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
			{ label: 'Shown cases', value: visibleCases },
			{ label: 'High-value threshold', value: result.high_value_threshold || 0 }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 5,
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
					orderDisplayStatus(order) || '',
					order.total ? ((order.total || '') + ' ' + (order.currency || '')) : ''
				].filter(Boolean).join(' · ');
				const primaryCommand = getOperationsAttentionRowCommand(queueKey, order);
				const primaryLabel = getOperationsAttentionRowActionLabel(queueKey);
				const detailsCommand = 'Show order ' + order.id + ' details';
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(displayText(caseMeta || (orderDisplayStatus(order) || 'Case'))) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(displayText(customerMeta || queue.description || '')) + '</div></td>';
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
			{ label: 'Operations report', command: 'Show operations report for today' },
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
		if (fulfillmentContext && fulfillmentContext.applicable === false) {
			return renderQueueWorkbenchIntro({
				result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Fulfillment queue is not currently applicable.',
				subtle: 'Shipping-related fulfillment signals are hidden while shipping is disabled for this store.',
				parserRule: parsed.parser_rule,
				title: 'Fulfillment candidates',
				contextLine: 'Shipping is disabled for this store.',
				badge: 'Not applicable'
			}, h);
		}
		const topPriority = buildFulfillmentHeroItem(fulfillmentContext, priorityItems, result);
		const supportingPriorityItems = filterFulfillmentSupportingPriorities(priorityItems, fulfillmentContext);
		const queueMatches = getQueueMatchCount(result);
		const visibleCases = getQueueVisibleCaseCount(result, 'orders');

		let html = renderQueueWorkbenchIntro({
			result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Fulfillment queue',
				subtle: 'Orders that may need shipping or tracking follow-up.',
				parserRule: parsed.parser_rule,
				title: 'Fulfillment candidates',
				contextLine: 'Top fulfillment cases from each queue.',
				badge: queueMatches + ' matches'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Queue matches', value: queueMatches },
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
			{ label: 'Shown cases', value: visibleCases },
			{ label: 'Store changes', value: 'No' }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 5,
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
				row += '<td><strong>' + h2.escapeHtml(displayText(caseMeta || (orderDisplayStatus(order) || 'Case'))) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(displayText(order.fulfillment_reason || queue.description || '')) + '</div></td>';
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
		const queueMatches = getQueueMatchCount(result);
		const visibleCases = getQueueVisibleCaseCount(result, 'products');
			const inventoryMode = String(inventoryContext.inventory_mode || result.inventory_mode || '');
			const inventorySubtle = stockManagementEnabled
				? 'Products that may need stock attention.'
				: 'Stock status view. Quantity tracking is not enabled for these products.';
		const heroMetrics = [
			{ label: 'Queue matches', value: queueMatches },
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
				badge: queueMatches + ' matches'
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
			{ label: 'Shown products', value: visibleCases },
			{ label: 'Stock tracking', value: stockManagementEnabled ? 'Enabled' : 'Off' }
		], h);
		html += renderInventoryProductQueueSections(queues, h, {
			maxRows: 5,
			stockManagementEnabled: stockManagementEnabled,
			inventoryMode: inventoryMode
		});
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show inventory queue' },
			{ label: stockManagementEnabled ? 'Fulfillment' : 'Sales report', command: stockManagementEnabled ? 'Show fulfillment queue' : 'Show sales report for the last 7 days' },
			{ label: 'Operations', command: 'Show operations attention queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
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
		const queueMatches = getQueueMatchCount(result);
		const visibleCases = getQueueVisibleCaseCount(result, 'orders');

		let html = renderQueueWorkbenchIntro({
			result: result,
				showAssistantIntro: true,
				showBoundary: true,
				summary: result.summary || 'Customer service queue',
				subtle: 'Customer follow-ups that may need a reply.',
				parserRule: parsed.parser_rule,
				title: 'Customer follow-up candidates',
				contextLine: 'Top customer cases from each queue.',
				badge: queueMatches + ' matches',
				cardClassName: 'yoro-queue-workbench-card-customer-service'
			}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Queue matches', value: queueMatches },
			{ label: 'On hold', value: serviceContext.on_hold_count || 0 },
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
			{ label: 'Shown cases', value: visibleCases },
			{ label: 'Auto-send', value: 'No' }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 5,
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
					orderDisplayStatus(order) || ''
				].filter(Boolean).join(' · ');
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(displayText(caseMeta)) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(displayText(order.followup_reason || '')) + '</div></td>';
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
				return 'On hold follow-up';
			case 'pending_payment_followup':
				return 'Pending payment';
			case 'saved_email_drafts':
				return 'Saved drafts';
			case 'failed_checkout_followup':
				return 'Failed checkout';
			default:
				return displayLabel(topic || 'queue');
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
			title = 'Most customer reply pressure is sitting in On hold orders.';
			message = 'There are ' + onHold + ' On hold follow-ups carrying most of the live backlog, plus ' + drafts + ' saved drafts you can finish quickly.';
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
				return 'Draft On hold reply';
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
				return 'On hold';
			case 'recent_failed_orders':
				return 'Failed orders';
			case 'high_value_open_orders':
				return 'High-value open';
			default:
				return displayLabel(topic || 'queue');
		}
	}

	function getOperationsAttentionRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'pending_payment_older_than_24h':
				return 'Draft payment follow-up';
			case 'processing_older_than_72h':
				return 'Preview delay email';
			case 'on_hold_older_than_48h':
				return 'Draft On hold reply';
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
			title = 'On hold orders are waiting for a clear decision.';
			message = 'There are ' + onHold + ' older orders in On hold status still sitting in backlog, with ' + pending + ' payment-delay cases also active.';
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
				return displayLabel(topic || 'overview');
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
				return displayLabel(topic || 'queue');
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
				return displayLabel(topic || 'queue');
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
		const stockStatusLabel = productStockStatusLabel(product);

		if (quantity === '' || quantity === null) {
			return inventoryMode === 'status_only'
				? stockStatusLabel
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
		const locale = getResultLocale(data);

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
		html += '<span class="yoro-status-badge">' + h.escapeHtml(orderDisplayStatus(order, locale)) + '</span>';
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
			html += '<th>' + h.escapeHtml(displayKeyLabel(key)) + '</th>';
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

		const toggle = rawTarget.closest('.yoro-report-toggle');
		if (toggle) {
			event.preventDefault();
			const card = toggle.closest('.yoro-report-presentation');
			const details = card && card.querySelector('.yoro-report-details');
			const state = h.getConversationState ? h.getConversationState() : {};
			if (!card || !details || !toggle.isConnected || details.id !== toggle.getAttribute('aria-controls')
				|| (h.getConversationState && state.conversation_id !== card.getAttribute('data-conversation-id'))) {
				return true;
			}
			const expanded = toggle.getAttribute('aria-expanded') !== 'true';
			details.hidden = !expanded;
			toggle.setAttribute('aria-expanded', String(expanded));
			toggle.textContent = toggle.getAttribute(expanded ? 'data-close-label' : 'data-open-label');
			return true;
		}

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
					'.yoro-row-action-menu,' +
				'.yoro-direct-action,' +
				'.yoro-modal-action'
			);

		if (!target) {
			return false;
		}

		if (target.classList.contains('yoro-direct-action')) {
			event.preventDefault();

			const action = target.getAttribute('data-direct-action') || '';
			const paramsRaw = target.getAttribute('data-direct-params') || '{}';
			const preview = target.getAttribute('data-direct-preview') === '1';
			let params = {};

			if (!action) {
				return true;
			}

			try {
				params = JSON.parse(paramsRaw) || {};
			} catch (error) {
				params = {};
			}

			executeOrderUiAction(h, action, params, preview);
			return true;
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

			executeOrderUiAction(h, 'orders.update_status', {
				order_ids: ids,
				status: 'on-hold'
			}, false);
			return true;
		}

		if (target.classList.contains('yoro-row-preview-hold')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				executeOrderUiAction(h, 'orders.update_status', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)],
					status: 'on-hold'
				}, true);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-run-hold')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				executeOrderUiAction(h, 'orders.update_status', {
					order_id: parseInt(orderId, 10),
					order_ids: [parseInt(orderId, 10)],
					status: 'on-hold'
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
						executeOrderUiAction(h, 'orders.update_status', {
							order_id: parseInt(orderId, 10),
							order_ids: [parseInt(orderId, 10)],
							status: 'on-hold'
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
