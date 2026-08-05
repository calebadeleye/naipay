'use client';

import Link from 'next/link';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { QueryState } from '@/components/ui/query-state';
import { SelectField } from '@/components/ui/select';
import { formatMoney } from '@/lib/format';
import { useLoanProducts } from '@/lib/loan-products/use-loan-products';

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'retired', label: 'Retired' },
];

export default function LoanProductsPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useLoanProducts({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  });

  return (
    <>
      <PageHeader
        title="Loan products"
        description="The priced products available to sell. Changing a product only affects loans booked from now on."
        actions={
          <Link href="/loan-products/new" className={buttonVariants({ variant: 'primary' })}>
            New product
          </Link>
        }
      />

      <div className="flex flex-wrap gap-3">
        <Field
          label="Search"
          placeholder="Code, name or description"
          value={search}
          onChange={(event) => {
            setPage(1);
            setSearch(event.target.value);
          }}
          className="w-72"
        />
        <SelectField
          label="Status"
          options={statusOptions}
          value={status}
          onChange={(event) => {
            setPage(1);
            setStatus(event.target.value);
          }}
          className="w-48"
        />
      </div>

      <QueryState isLoading={isLoading} error={error}>
        {data ? (
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                  <th className="px-4 py-3">Product</th>
                  <th className="px-4 py-3">Amount range</th>
                  <th className="px-4 py-3">Tenor</th>
                  <th className="px-4 py-3">Interest</th>
                  <th className="px-4 py-3">Frequency</th>
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((product) => (
                  <tr key={product.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <Link
                        href={`/loan-products/${product.id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {product.name}
                      </Link>
                      <p className="numeric text-xs text-slate-500">{product.code}</p>
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">
                      {formatMoney(product.amount.minimum)} – {formatMoney(product.amount.maximum)}
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">
                      {product.tenor.minimum}–{product.tenor.maximum} {product.tenor.unit_label.toLowerCase()}
                    </td>
                    <td className="numeric px-4 py-3 text-slate-700">
                      {product.interest.rate}% ({product.interest.method_label})
                    </td>
                    <td className="px-4 py-3 text-slate-700">{product.repayment.frequency_label}</td>
                    <td className="px-4 py-3">
                      <Badge tone={product.is_active ? 'success' : 'neutral'}>
                        {product.is_active ? 'Active' : 'Retired'}
                      </Badge>
                    </td>
                  </tr>
                ))}
                {data.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                      No loan products match these filters.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
            <Pagination meta={data.pagination} page={page} onPageChange={setPage} />
          </Card>
        ) : null}
      </QueryState>
    </>
  );
}
