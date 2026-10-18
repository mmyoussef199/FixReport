<?php
/** Fixed, versioned report vocabulary. New documents freeze a copy at assembly. */
class FixReport_Report_Language {
	public static function valid( $locale ) {
		return in_array( $locale, array( 'en_US', 'ar' ), true );
	}

	public static function vocabulary( $locale ) {
		$english = array(
			'labels' => array(
				'summary' => 'Executive / Assessment Summary', 'findings' => 'Findings / Professional Notes',
				'recommendations' => 'Recommendations / Attention Required', 'final_notes' => 'Final Notes',
				'activities' => 'Activities', 'checklist' => 'Maintenance Checklist',
				'start_state' => 'Starting Site State', 'end_state' => 'Ending Site State',
				'comparison' => 'Snapshot Comparison', 'overview' => 'Overview',
				'site' => 'Site Information', 'environment' => 'WordPress & Environment',
				'updates' => 'Updates at Capture', 'content' => 'Content Overview',
				'plugins' => 'Plugin Inventory', 'themes' => 'Theme Inventory',
				'content_types' => 'Custom Content', 'health_items' => 'Maintenance Health Findings',
				'technical' => 'Technical Evidence',
			),
			'ui' => array(
				'report' => 'Report', 'initial_assessment' => 'Initial Assessment', 'maintenance' => 'Maintenance Report',
				'document_contract' => 'Document contract', 'period' => 'Period', 'through' => 'through',
				'exclusive' => 'exclusive', 'timezone' => 'Time zone', 'snapshot' => 'Snapshot',
				'captured' => 'captured', 'approximation' => 'Approximation, not a boundary capture',
				'integrity_warning' => 'Some stored details could not be interpreted safely.',
				'no_activities' => 'No Activities included.', 'no_checklist' => 'No checklist rows included.',
				'legacy_clock' => 'Legacy local clock; exact chronology uncertain',
				'health_coverage' => 'Maintenance health coverage', 'critical_findings' => 'critical findings',
				'recommended_findings' => 'recommended findings',
				'health_caveat' => 'These are bounded local checks, not a security or performance audit.',
				'comparison_caveat' => 'Derived comparison of selected historical snapshots. This is not a claim of causation.',
				'finalized' => 'Finalized', 'by_user' => 'by user',
				'frozen_caveat' => 'This export uses only the integrity-verified frozen document.',
				'draft' => 'DRAFT / NOT FINALIZED', 'historical' => 'FINALIZED / HISTORICAL',
				'evidence' => 'Evidence', 'none' => 'None', 'date' => 'Date', 'status' => 'Status',
				'source' => 'Source', 'details' => 'Details', 'page' => 'Page', 'of' => 'of',
			),
			'statuses' => array(
				'pending' => 'Pending', 'completed' => 'Completed',
				'not_applicable' => 'Not applicable', 'requires_attention' => 'Requires attention',
			),
			'sources' => array( 'manual' => 'Manual', 'automatic' => 'Automatic' ),
			'relations' => array(
				'initial' => 'Initial', 'at_or_before_start' => 'At or before start',
				'late_after_start' => 'After start', 'at_or_after_end' => 'At or after end',
				'early_before_end' => 'Before end',
			),
		);
		if ( 'ar' !== $locale ) { return $english; }
		return array(
			'labels' => array(
				'summary' => 'الملخص التنفيذي / التقييمي', 'findings' => 'النتائج / الملاحظات المهنية',
				'recommendations' => 'التوصيات / ما يتطلب الانتباه', 'final_notes' => 'الملاحظات الختامية',
				'activities' => 'الأنشطة', 'checklist' => 'قائمة فحص الصيانة',
				'start_state' => 'حالة الموقع عند البداية', 'end_state' => 'حالة الموقع عند النهاية',
				'comparison' => 'مقارنة لقطات الموقع', 'overview' => 'نظرة عامة',
				'site' => 'معلومات الموقع', 'environment' => 'ووردبريس وبيئة التشغيل',
				'updates' => 'التحديثات وقت الالتقاط', 'content' => 'نظرة عامة على المحتوى',
				'plugins' => 'إضافات الموقع', 'themes' => 'قوالب الموقع',
				'content_types' => 'أنواع المحتوى المخصصة', 'health_items' => 'نتائج فحص الصيانة',
				'technical' => 'الأدلة الفنية',
			),
			'ui' => array(
				'report' => 'التقرير', 'initial_assessment' => 'التقييم الأولي', 'maintenance' => 'تقرير الصيانة',
				'document_contract' => 'إصدار المستند', 'period' => 'الفترة', 'through' => 'حتى',
				'exclusive' => 'غير شامل', 'timezone' => 'المنطقة الزمنية', 'snapshot' => 'لقطة الموقع',
				'captured' => 'تم الالتقاط', 'approximation' => 'تقدير، وليس لقطة عند حد الفترة',
				'integrity_warning' => 'تعذر تفسير بعض التفاصيل المخزنة بأمان.',
				'no_activities' => 'لا توجد أنشطة مدرجة.', 'no_checklist' => 'لا توجد بنود فحص مدرجة.',
				'legacy_clock' => 'توقيت محلي قديم؛ الترتيب الزمني الدقيق غير مؤكد',
				'health_coverage' => 'نطاق فحص صحة الصيانة', 'critical_findings' => 'نتائج حرجة',
				'recommended_findings' => 'نتائج موصى بها',
				'health_caveat' => 'هذه فحوص محلية محدودة وليست تدقيقاً أمنياً أو للأداء.',
				'comparison_caveat' => 'مقارنة مشتقة من لقطات تاريخية محددة، ولا تثبت علاقة سببية.',
				'finalized' => 'اكتمل اعتماد التقرير', 'by_user' => 'بواسطة المستخدم',
				'frozen_caveat' => 'يعتمد هذا التصدير حصرياً على المستند التاريخي المتحقق من سلامته.',
				'draft' => 'مسودة / غير معتمد', 'historical' => 'معتمد / تاريخي',
				'evidence' => 'الدليل', 'none' => 'لا يوجد', 'date' => 'التاريخ', 'status' => 'الحالة',
				'source' => 'المصدر', 'details' => 'التفاصيل', 'page' => 'صفحة', 'of' => 'من',
			),
			'statuses' => array(
				'pending' => 'قيد الانتظار', 'completed' => 'مكتمل',
				'not_applicable' => 'لا ينطبق', 'requires_attention' => 'يتطلب الانتباه',
			),
			'sources' => array( 'manual' => 'يدوي', 'automatic' => 'تلقائي' ),
			'relations' => array(
				'initial' => 'أولي', 'at_or_before_start' => 'عند البداية أو قبلها',
				'late_after_start' => 'بعد البداية', 'at_or_after_end' => 'عند النهاية أو بعدها',
				'early_before_end' => 'قبل النهاية',
			),
		);
	}

	/** Legacy contracts keep their original section labels and English template wording. */
	public static function for_document( $document ) {
		if ( '7.3' === ( $document['contract_version'] ?? '' ) && isset( $document['presentation'] ) && is_array( $document['presentation'] ) ) {
			return $document['presentation'];
		}
		$english = self::vocabulary( 'en_US' );
		$english['labels'] = array_replace( $english['labels'], $document['labels'] ?? array() );
		return $english;
	}

	public static function text( $presentation, $group, $key ) {
		return $presentation[ $group ][ $key ] ?? $key;
	}
}
