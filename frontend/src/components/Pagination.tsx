import { Button } from './Button'
import { useI18n } from '../i18n/I18nContext'
import './Pagination.css'

/**
 * Cursor paging footer: the number of rows loaded, an optional note about what
 * the visible search covers, and a "Load more" button while the server says a
 * next cursor exists.
 */
export function LoadMore({
  count,
  hasMore,
  loading = false,
  onLoadMore,
  note,
}: {
  count: number
  hasMore: boolean
  loading?: boolean
  onLoadMore: () => void
  note?: string
}) {
  const { m } = useI18n()

  return (
    <div className="load-more">
      <p className="load-more-count" role="status">{m.common.loadedCount(count)}</p>
      {note && <p className="load-more-note">{note}</p>}
      <div className="load-more-action">
        {hasMore && (
          <Button variant="secondary" size="sm" loading={loading} loadingLabel={m.common.loadingMore} onClick={onLoadMore}>
            {m.common.loadMore}
          </Button>
        )}
      </div>
    </div>
  )
}
