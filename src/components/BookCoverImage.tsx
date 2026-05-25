import { useEffect, useState, type ImgHTMLAttributes } from 'react'
import defaultBookCover from '../assets/default-book-cover.jpg'

interface BookCoverImageProps extends Omit<ImgHTMLAttributes<HTMLImageElement>, 'src'> {
  src?: string | null
}

export function resolveBookCoverSrc(src?: string | null): string {
  if (typeof src === 'string' && src.trim() !== '') {
    return src
  }

  return defaultBookCover
}

export default function BookCoverImage({ src, onError, ...props }: BookCoverImageProps) {
  const resolvedSrc = resolveBookCoverSrc(src)
  const [currentSrc, setCurrentSrc] = useState(resolvedSrc)

  useEffect(() => {
    setCurrentSrc(resolvedSrc)
  }, [resolvedSrc])

  return (
    <img
      {...props}
      src={currentSrc}
      onError={(event) => {
        if (currentSrc !== defaultBookCover) {
          setCurrentSrc(defaultBookCover)
        }

        onError?.(event)
      }}
    />
  )
}
