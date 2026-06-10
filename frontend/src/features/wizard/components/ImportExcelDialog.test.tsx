import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'

import ImportExcelDialog from './ImportExcelDialog'
import { useWizardStore } from '@/features/wizard/wizardStore'

function encodeUtf8(value: string): Uint8Array {
  return new TextEncoder().encode(value)
}

function concatBytes(chunks: Uint8Array[]): Uint8Array {
  const length = chunks.reduce((total, chunk) => total + chunk.length, 0)
  const result = new Uint8Array(length)
  let offset = 0
  for (const chunk of chunks) {
    result.set(chunk, offset)
    offset += chunk.length
  }
  return result
}

function columnName(index: number): string {
  let current = index + 1
  let name = ''
  while (current > 0) {
    const remainder = (current - 1) % 26
    name = String.fromCharCode(65 + remainder) + name
    current = Math.floor((current - 1) / 26)
  }
  return name
}

function escapeXml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;')
}

function crc32(bytes: Uint8Array): number {
  const table = new Uint32Array(256)
  for (let index = 0; index < 256; index += 1) {
    let crc = index
    for (let bit = 0; bit < 8; bit += 1) {
      crc = 1 === (crc & 1) ? 0xedb88320 ^ (crc >>> 1) : crc >>> 1
    }
    table[index] = crc >>> 0
  }

  let crc = 0xffffffff
  for (const byte of bytes) {
    crc = table[(crc ^ byte) & 0xff] ^ (crc >>> 8)
  }

  return (crc ^ 0xffffffff) >>> 0
}

function buildZip(entries: Array<{ name: string; content: Uint8Array }>): Uint8Array {
  const localParts: Uint8Array[] = []
  const centralParts: Uint8Array[] = []
  const offsets: number[] = []
  let currentOffset = 0

  for (const entry of entries) {
    const nameBytes = encodeUtf8(entry.name)
    const crc = crc32(entry.content)

    const localHeader = new Uint8Array(30 + nameBytes.length)
    const localView = new DataView(localHeader.buffer)
    localView.setUint32(0, 0x04034b50, true)
    localView.setUint16(4, 20, true)
    localView.setUint16(6, 0, true)
    localView.setUint16(8, 0, true)
    localView.setUint16(10, 0, true)
    localView.setUint16(12, 0, true)
    localView.setUint32(14, crc, true)
    localView.setUint32(18, entry.content.length, true)
    localView.setUint32(22, entry.content.length, true)
    localView.setUint16(26, nameBytes.length, true)
    localView.setUint16(28, 0, true)
    localHeader.set(nameBytes, 30)

    localParts.push(localHeader, entry.content)
    offsets.push(currentOffset)
    currentOffset += localHeader.length + entry.content.length

    const centralHeader = new Uint8Array(46 + nameBytes.length)
    const centralView = new DataView(centralHeader.buffer)
    centralView.setUint32(0, 0x02014b50, true)
    centralView.setUint16(4, 20, true)
    centralView.setUint16(6, 20, true)
    centralView.setUint16(8, 0, true)
    centralView.setUint16(10, 0, true)
    centralView.setUint16(12, 0, true)
    centralView.setUint16(14, 0, true)
    centralView.setUint32(16, crc, true)
    centralView.setUint32(20, entry.content.length, true)
    centralView.setUint32(24, entry.content.length, true)
    centralView.setUint16(28, nameBytes.length, true)
    centralView.setUint16(30, 0, true)
    centralView.setUint16(32, 0, true)
    centralView.setUint16(34, 0, true)
    centralView.setUint16(36, 0, true)
    centralView.setUint32(38, 0, true)
    centralView.setUint32(42, offsets[offsets.length - 1], true)
    centralHeader.set(nameBytes, 46)

    centralParts.push(centralHeader)
  }

  const centralDirectory = concatBytes(centralParts)
  const endOfCentralDirectory = new Uint8Array(22)
  const endView = new DataView(endOfCentralDirectory.buffer)
  endView.setUint32(0, 0x06054b50, true)
  endView.setUint16(4, 0, true)
  endView.setUint16(6, 0, true)
  endView.setUint16(8, entries.length, true)
  endView.setUint16(10, entries.length, true)
  endView.setUint32(12, centralDirectory.length, true)
  endView.setUint32(16, currentOffset, true)
  endView.setUint16(20, 0, true)

  return concatBytes([...localParts, centralDirectory, endOfCentralDirectory])
}

function buildXlsxFile(rows: string[][], name = 'ffbb-import.xlsx'): File {
  const sheetXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    ${rows
      .map(
        (row, rowIndex) =>
          `<row r="${rowIndex + 1}">${row
            .map(
              (cell, cellIndex) =>
                `<c r="${columnName(cellIndex)}${rowIndex + 1}" t="inlineStr"><is><t>${escapeXml(cell)}</t></is></c>`
            )
            .join('')}</row>`
      )
      .join('')}
  </sheetData>
</worksheet>`

  const entries = buildZip([
    {
      name: '[Content_Types].xml',
      content: encodeUtf8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml" />
  <Default Extension="xml" ContentType="application/xml" />
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" />
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" />
</Types>`),
    },
    {
      name: '_rels/.rels',
      content: encodeUtf8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml" />
</Relationships>`),
    },
    {
      name: 'xl/workbook.xml',
      content: encodeUtf8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Feuil1" sheetId="1" r:id="rId1" />
  </sheets>
</workbook>`),
    },
    {
      name: 'xl/_rels/workbook.xml.rels',
      content: encodeUtf8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml" />
</Relationships>`),
    },
    {
      name: 'xl/worksheets/sheet1.xml',
      content: encodeUtf8(sheetXml),
    },
  ])

  return new File([entries], name, {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
}

beforeEach(() => {
  localStorage.clear()
  useWizardStore.getState().resetWizard()
})

afterEach(() => {
  cleanup()
})

describe('ImportExcelDialog', () => {
  it('shows a preview and creates teams from the imported workbook', async () => {
    const onClose = vi.fn()
    const file = buildXlsxFile([
      ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
      ['U15 A', 'U15', '1001', '123456 - club - North Stars'],
      ['U18 A', 'U18', '1002', '123456 - club - North Stars'],
    ])

    render(<ImportExcelDialog isOpen onClose={onClose} />)

    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    fireEvent.change(input, { target: { files: [file] } })

    await waitFor(() => {
      expect(screen.getByText('U15 A')).toBeInTheDocument()
      expect(screen.getByText('U18 A')).toBeInTheDocument()
      expect(screen.getByText('U15')).toBeInTheDocument()
      expect(screen.getByText('U18')).toBeInTheDocument()
    }, { timeout: 5000 })

    fireEvent.click(screen.getAllByRole('button', { name: 'Confirmer l’import' })[0])

    await waitFor(() => {
      expect(useWizardStore.getState().data.teams).toHaveLength(2)
      expect(useWizardStore.getState().data.teams[0]).toMatchObject({
        name: 'U15 A',
        level: 'U15',
      })
      expect(useWizardStore.getState().data.teams[1]).toMatchObject({
        name: 'U18 A',
        level: 'U18',
      })
    }, { timeout: 5000 })
  }, 10000)

  it('skips duplicate teams already present in the wizard', async () => {
    const onClose = vi.fn()
    useWizardStore.getState().addTeam()
    const existingTeam = useWizardStore.getState().data.teams.at(-1)
    if (existingTeam) {
      useWizardStore.getState().updateTeam(existingTeam.id, {
        name: 'U15 A',
        level: 'U15',
      })
    }

    const file = buildXlsxFile([
      ['Nom', 'Catégorie', 'Numéro', 'Organisme'],
      ['U15 A', 'U15', '1001', '123456 - club - North Stars'],
      ['U18 A', 'U18', '1002', '123456 - club - North Stars'],
    ])

    render(<ImportExcelDialog isOpen onClose={onClose} />)

    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    fireEvent.change(input, { target: { files: [file] } })

    await waitFor(() => {
      expect(screen.getByText('Doublon — ignorée')).toBeInTheDocument()
    }, { timeout: 5000 })

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer l’import' }))

    await waitFor(() => {
      expect(useWizardStore.getState().data.teams).toHaveLength(2)
      expect(screen.getByText(/1 doublon ignoré/i)).toBeInTheDocument()
    }, { timeout: 5000 })
  })

  it('rejects invalid files before parsing', async () => {
    const onClose = vi.fn()
    const file = new File(['plain text'], 'ffbb-import.txt', { type: 'text/plain' })

    render(<ImportExcelDialog isOpen onClose={onClose} />)

    const input = document.querySelector('input[type="file"]') as HTMLInputElement
    fireEvent.change(input, { target: { files: [file] } })

    expect(await screen.findByRole('alert')).toHaveTextContent('Format invalide. Importez un fichier .xlsx.')
    expect(screen.queryByText('Prévisualisation')).not.toBeInTheDocument()
  })
})
