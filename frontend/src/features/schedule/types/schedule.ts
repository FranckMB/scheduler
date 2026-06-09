export interface SchedulePdfExport {
  pdfExportStatus: 'pending' | 'done' | 'failed'
  pdfExportUrl: string | null
}
