import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table'
import type { ReactNode } from 'react'

type Props<T> = {
  data: T[]
  columns: ColumnDef<T, unknown>[]
  empty?: ReactNode
  caption?: string
}

export function DataTable<T>({ data, columns, empty, caption }: Props<T>) {
  // TanStack Table owns this instance and its functions; React Compiler must not memoize it.
  // eslint-disable-next-line react-hooks/incompatible-library
  const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() })

  if (!data.length && empty) return <>{empty}</>

  return <div className="sb-data-table-wrap">
    <table className="sb-data-table">
      {caption && <caption className="sr-only">{caption}</caption>}
      <thead><tr>{table.getHeaderGroups()[0]?.headers.map((header) => <th key={header.id} scope="col">{header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}</th>)}</tr></thead>
      <tbody>{table.getRowModel().rows.map((row) => <tr key={row.id}>{row.getVisibleCells().map((cell) => <td key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>)}</tr>)}</tbody>
    </table>
  </div>
}
